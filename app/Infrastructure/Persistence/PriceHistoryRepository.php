<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Pricing\PriceHistoryAggregates;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class PriceHistoryRepository
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * All the historical windows the scoring engine needs, in one pass.
     *
     * Four separate "cheapest in N days" queries would read the same rows four
     * times; aggregate FILTER computes every window from a single scan.
     *
     * The predicate on collected_at appears twice on purpose. The outer bound lets
     * the planner discard partitions outside 180 days entirely, which is the whole
     * point of partitioning this table; the FILTER clauses then slice that range.
     */
    public function aggregatesFor(int $productId, int $windowDays = 180): PriceHistoryAggregates
    {
        /** @var object{min_30: string|null, min_60: string|null, min_90: string|null, min_180: string|null, avg_90: string|null, median_90: string|null, samples_30: int, samples_total: int, history_since: string|null}|null $row */
        $row = $this->connection()->selectOne(<<<'SQL'
            SELECT
              min(price) FILTER (WHERE collected_at >= now() - interval '30 days')  AS min_30,
              min(price) FILTER (WHERE collected_at >= now() - interval '60 days')  AS min_60,
              min(price) FILTER (WHERE collected_at >= now() - interval '90 days')  AS min_90,
              min(price) FILTER (WHERE collected_at >= now() - interval '180 days') AS min_180,
              round(avg(price) FILTER (WHERE collected_at >= now() - interval '90 days'), 2) AS avg_90,
              -- percentile_cont has no numeric overload: it takes and returns
              -- double precision, so the result must be cast back before round().
              round((percentile_cont(0.5) WITHIN GROUP (ORDER BY price)
                FILTER (WHERE collected_at >= now() - interval '90 days'))::numeric, 2) AS median_90,
              count(*) FILTER (WHERE collected_at >= now() - interval '30 days')    AS samples_30,
              count(*)                                                             AS samples_total,
              min(collected_at)                                                    AS history_since
            FROM price_history
            WHERE product_id = ?
              AND collected_at >= now() - (? || ' days')::interval
        SQL, [$productId, $windowDays]);

        return PriceHistoryAggregates::fromRow($row === null ? [] : (array) $row);
    }

    /**
     * Appends price observations, skipping the ones that would add nothing.
     *
     * A row is written only when the price actually moved, or when the last sample
     * is older than the heartbeat interval. Recording every read instead would
     * inflate the largest table in the system by roughly an order of magnitude
     * while adding no analytical value: a thousand identical readings tell the
     * scoring engine exactly what one does.
     *
     * The decision is made inside the statement rather than in PHP so that the
     * whole batch costs one round trip, and so two workers cannot both decide to
     * write the same observation.
     *
     * @param  list<array{product_id: int, marketplace_id: int, price: string, list_price: string|null, in_stock: bool}>  $observations
     * @return list<int> ids of the products actually recorded
     */
    public function recordObservations(array $observations, ?int $heartbeatHours = null): array
    {
        if ($observations === []) {
            return [];
        }

        $heartbeatHours ??= (int) config('promohub.pricing.heartbeat_hours', 6);

        $placeholders = [];
        $bindings = [];

        foreach ($observations as $observation) {
            $placeholders[] = '(?::bigint, ?::bigint, ?::money_brl, ?::money_brl, ?::boolean)';

            array_push(
                $bindings,
                $observation['product_id'],
                $observation['marketplace_id'],
                $observation['price'],
                $observation['list_price'],
                $observation['in_stock'],
            );
        }

        $values = implode(', ', $placeholders);
        $bindings[] = $heartbeatHours;

        /*
         * The lateral bounds its own scan to 30 days so the planner can prune
         * partitions; a product with no sample inside that window is treated as
         * having no history and is always recorded.
         */
        /*
         * RETURNING rather than a row count, so the caller knows exactly which
         * products moved. Only those need re-evaluating: a product whose price did
         * not change would score identically and be discarded by the dedupe index.
         */
        $rows = $this->connection()->select(<<<SQL
            INSERT INTO price_history (product_id, marketplace_id, price, list_price, in_stock, source, collected_at)
            SELECT observed.product_id, observed.marketplace_id, observed.price, observed.list_price,
                   observed.in_stock, 'api', now()
            FROM (VALUES {$values}) AS observed (product_id, marketplace_id, price, list_price, in_stock)
            LEFT JOIN LATERAL (
                SELECT ph.price AS last_price, ph.collected_at AS last_collected_at
                FROM price_history ph
                WHERE ph.product_id = observed.product_id
                  AND ph.collected_at >= now() - interval '30 days'
                ORDER BY ph.collected_at DESC
                LIMIT 1
            ) previous ON true
            WHERE previous.last_price IS NULL
               OR previous.last_price IS DISTINCT FROM observed.price
               OR previous.last_collected_at < now() - (? || ' hours')::interval
            RETURNING product_id
        SQL, $bindings);

        /** @var list<object{product_id: int}> $rows */
        return array_values(array_map(
            static fn (object $row): int => (int) $row->product_id,
            $rows
        ));
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
