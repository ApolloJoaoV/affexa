<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace;

use App\Domain\Marketplace\Exceptions\CircuitOpenException;
use App\Models\Marketplace;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a failing marketplace out of rotation.
 *
 * Without this, an API returning 503 for an hour would let every scheduled fetch
 * enqueue thousands of jobs that each burn a timeout before failing, starving the
 * queues that publish working promotions. Opening the circuit turns that into a
 * handful of cheap refusals.
 *
 * State lives on the marketplaces row rather than in Redis so that the scheduler,
 * the dashboard and the health check all read the same authority, and it survives a
 * Redis flush.
 */
final class CircuitBreaker
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * @throws CircuitOpenException when the marketplace is currently held out
     */
    public function ensureClosed(Marketplace $marketplace): void
    {
        if ($marketplace->circuit_open_until === null) {
            return;
        }

        $openUntil = CarbonImmutable::instance($marketplace->circuit_open_until);

        if ($openUntil->isFuture()) {
            throw new CircuitOpenException($marketplace->slug, $openUntil);
        }
    }

    /**
     * Clears the failure streak after a call succeeds.
     *
     * Only writes when there is something to clear: the overwhelming majority of
     * calls succeed against an already healthy marketplace, and an UPDATE per
     * successful API call would be pure write amplification.
     */
    public function recordSuccess(Marketplace $marketplace): void
    {
        if ($marketplace->consecutive_failures === 0 && $marketplace->circuit_open_until === null) {
            return;
        }

        $this->connection()->update(<<<'SQL'
            UPDATE marketplaces
            SET consecutive_failures = 0,
                circuit_open_until = NULL,
                last_error_at = NULL,
                last_error_message = NULL,
                updated_at = now()
            WHERE id = ?
        SQL, [$marketplace->id]);

        $marketplace->forceFill([
            'consecutive_failures' => 0,
            'circuit_open_until' => null,
            'last_error_at' => null,
            'last_error_message' => null,
        ])->syncOriginal();
    }

    /**
     * Records a failure and opens the circuit once the threshold is crossed.
     *
     * The increment happens in SQL rather than by reading, adding and writing:
     * several workers fail concurrently against the same dead API, and a
     * read-modify-write would lose most of those failures and never trip.
     *
     * @return bool whether this failure opened the circuit
     */
    public function recordFailure(Marketplace $marketplace, string $reason): bool
    {
        $threshold = $this->failureThreshold();
        $cooldownMinutes = $this->cooldownMinutes();

        /** @var object{consecutive_failures: int, circuit_open_until: string|null} $row */
        $row = $this->connection()->selectOne(<<<'SQL'
            UPDATE marketplaces
            SET consecutive_failures = consecutive_failures + 1,
                last_error_at = now(),
                last_error_message = ?,
                circuit_open_until = CASE
                    WHEN consecutive_failures + 1 >= ?
                        THEN now() + (? || ' minutes')::interval
                    ELSE circuit_open_until
                END,
                updated_at = now()
            WHERE id = ?
            RETURNING consecutive_failures, circuit_open_until
        SQL, [$this->truncate($reason), $threshold, $cooldownMinutes, $marketplace->id]);

        $marketplace->forceFill([
            'consecutive_failures' => $row->consecutive_failures,
            'circuit_open_until' => $row->circuit_open_until,
            'last_error_at' => CarbonImmutable::now(),
            'last_error_message' => $this->truncate($reason),
        ])->syncOriginal();

        return $row->consecutive_failures >= $threshold;
    }

    public function failureThreshold(): int
    {
        return max(1, (int) config('promohub.circuit_breaker.failure_threshold', 5));
    }

    public function cooldownMinutes(): int
    {
        return max(1, (int) config('promohub.circuit_breaker.cooldown_minutes', 15));
    }

    /**
     * Error messages from upstream can be entire HTML pages; only the useful head
     * of it belongs on the marketplace row.
     */
    private function truncate(string $reason): string
    {
        return mb_substr($reason, 0, 500);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
