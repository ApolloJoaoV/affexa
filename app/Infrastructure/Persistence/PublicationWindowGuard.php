<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Publishing\Channel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Claims the republication cooldown for a product on a channel.
 *
 * The check and the claim are one statement, decided by the exclusion constraint
 * on publication_windows. That matters because the alternative — query for a
 * recent publication, then insert — is a race two workers evaluating the same
 * product will lose: both read "nothing recent" and both publish.
 *
 * A rejected claim is an ordinary outcome, not an error, so it comes back as
 * false rather than an exception.
 */
final class PublicationWindowGuard
{
    /**
     * PostgreSQL SQLSTATE for exclusion_violation.
     */
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * @param  int  $cooldownMinutes  how long this product stays blocked on this channel
     * @return bool false when a live window already covers this product and channel
     */
    public function reserve(int $productId, Channel $channel, int $cooldownMinutes, ?int $promotionId = null): bool
    {
        try {
            /*
             * Wrapped in a transaction so that a rejected claim is contained.
             *
             * In PostgreSQL a failed statement aborts the entire transaction: every
             * later statement raises "current transaction is aborted" until a
             * rollback. Since the publish path calls this inside a transaction that
             * also updates the promotion and records the publication, swallowing the
             * violation without a savepoint would poison all of that work.
             *
             * Laravel issues a real transaction at the outermost level and a
             * SAVEPOINT when already inside one, and rolls back to that savepoint on
             * failure — which is precisely the containment needed here.
             */
            $this->connection()->transaction(function (ConnectionInterface $connection) use ($productId, $channel, $cooldownMinutes, $promotionId): void {
                $connection->insert(
                    <<<'SQL'
                        INSERT INTO publication_windows (product_id, channel, "window", promotion_id)
                        VALUES (?, ?, tstzrange(now(), now() + (? || ' minutes')::interval), ?)
                    SQL,
                    [$productId, $channel->value, $cooldownMinutes, $promotionId]
                );
            });

            return true;
        } catch (QueryException $exception) {
            if ($this->isExclusionViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Whether a live window currently blocks this product on this channel.
     */
    public function isBlocked(int $productId, Channel $channel): bool
    {
        return $this->connection()->scalar(
            <<<'SQL'
                SELECT count(*) FROM publication_windows
                WHERE product_id = ? AND channel = ? AND "window" @> now()
            SQL,
            [$productId, $channel->value]
        ) > 0;
    }

    private function isExclusionViolation(QueryException $exception): bool
    {
        return $exception->getCode() === self::EXCLUSION_VIOLATION;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
