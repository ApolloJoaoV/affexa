<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * The range partitioned tables and everything that differs between them.
 *
 * Keeping the index and storage definitions here, rather than duplicated in a
 * migration and again in the maintenance command, means every partition ever
 * created carries the same indexes and the same autovacuum tuning.
 */
enum PartitionedTable: string
{
    case PriceHistory = 'price_history';
    case ApiCallLogs = 'api_call_logs';

    public function partitionKey(): string
    {
        return match ($this) {
            self::PriceHistory => 'collected_at',
            self::ApiCallLogs => 'occurred_at',
        };
    }

    public function granularity(): PartitionGranularity
    {
        return match ($this) {
            self::PriceHistory => PartitionGranularity::Monthly,
            // Telemetry is kept for weeks, not months, so weekly partitions keep
            // each DETACH small.
            self::ApiCallLogs => PartitionGranularity::Weekly,
        };
    }

    /**
     * How many future periods the ensure command keeps provisioned. Running out
     * of partitions means every insert fails, so the margin is deliberate.
     */
    public function periodsAhead(): int
    {
        return match ($this) {
            self::PriceHistory => 3,
            self::ApiCallLogs => 4,
        };
    }

    /**
     * Index DDL applied to each new leaf partition.
     *
     * @return list<string>
     */
    public function indexesFor(string $partition): array
    {
        return match ($this) {
            self::PriceHistory => [
                /*
                 * BRIN over the partition key: inserts arrive in time order, so
                 * the physical layout already correlates with the column. A BRIN
                 * costs a fraction of a B-tree's space and answers range scans
                 * well, which is all this column is ever used for.
                 */
                "CREATE INDEX IF NOT EXISTS idx_{$partition}_collected_brin
                    ON {$partition} USING brin (collected_at) WITH (pages_per_range = 32)",
                // Answers "price history for this product, newest first", which is
                // the shape of every analytical query in the scoring pipeline.
                "CREATE INDEX IF NOT EXISTS idx_{$partition}_product_collected
                    ON {$partition} (product_id, collected_at DESC)",
            ],
            self::ApiCallLogs => [
                "CREATE INDEX IF NOT EXISTS idx_{$partition}_occurred_brin
                    ON {$partition} USING brin (occurred_at) WITH (pages_per_range = 32)",
                "CREATE INDEX IF NOT EXISTS idx_{$partition}_marketplace_occurred
                    ON {$partition} (marketplace_id, occurred_at DESC)",
            ],
        };
    }

    /**
     * Storage parameters for each leaf partition.
     *
     * These cannot be set on the partitioned parent — PostgreSQL raises "cannot
     * specify storage parameters for a partitioned table" — so they are applied
     * per partition at creation time. The defaults are wrong for an
     * insert-intensive table: at a 20% scale factor a partition with tens of
     * millions of rows would wait far too long to be vacuumed and analysed,
     * leaving the planner with stale statistics.
     *
     * @return array<string, string>
     */
    public function storageParameters(): array
    {
        return [
            'autovacuum_vacuum_scale_factor' => '0.02',
            'autovacuum_analyze_scale_factor' => '0.01',
            'autovacuum_vacuum_cost_limit' => '2000',
        ];
    }
}
