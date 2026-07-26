<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records every outbound marketplace call.
 *
 * This is what makes "the Amazon connector got slow last Tuesday" answerable
 * rather than a matter of opinion. Retention is short and the table is partitioned
 * weekly, so the volume is bounded.
 */
final class ApiCallLogger
{
    /**
     * Only a head of the body is stored, and only for failures. Logging full
     * responses would dwarf the price history table and could capture data we have
     * no reason to retain.
     */
    private const EXCERPT_LENGTH = 1000;

    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    public function record(
        int $marketplaceId,
        string $endpoint,
        ?int $httpStatus,
        int $durationMs,
        ?string $requestSignature = null,
        ?string $responseBody = null,
    ): void {
        $failed = $httpStatus === null || $httpStatus >= 400;

        $this->connection()->insert(<<<'SQL'
            INSERT INTO api_call_logs
                (marketplace_id, endpoint, http_status, duration_ms, request_hash, response_excerpt)
            VALUES (?, ?, ?, ?, ?, ?)
        SQL, [
            $marketplaceId,
            $endpoint,
            $httpStatus,
            max(0, $durationMs),
            $requestSignature === null ? null : '\x'.hash('sha256', $requestSignature),
            $failed && $responseBody !== null ? mb_substr($responseBody, 0, self::EXCERPT_LENGTH) : null,
        ]);
    }

    /**
     * Ninety-fifth percentile latency per marketplace over a recent window, for the
     * dashboard. The average hides exactly the tail that makes a fetch run time out.
     *
     * @return list<array{marketplace_id: int, p95_ms: float, calls: int, error_rate: float}>
     */
    public function latencyPercentiles(int $hours = 24): array
    {
        /** @var list<object{marketplace_id: int, p95_ms: float|string|null, calls: int, error_rate: float|string}> $rows */
        $rows = $this->connection()->select(<<<'SQL'
            SELECT marketplace_id,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95_ms,
                   count(*) AS calls,
                   avg(CASE WHEN http_status IS NULL OR http_status >= 400 THEN 1 ELSE 0 END)::float AS error_rate
            FROM api_call_logs
            WHERE occurred_at >= now() - (? || ' hours')::interval
            GROUP BY marketplace_id
            ORDER BY marketplace_id
        SQL, [$hours]);

        return array_map(
            fn (object $row): array => [
                'marketplace_id' => (int) $row->marketplace_id,
                'p95_ms' => (float) ($row->p95_ms ?? 0),
                'calls' => (int) $row->calls,
                'error_rate' => (float) $row->error_rate,
            ],
            $rows
        );
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
