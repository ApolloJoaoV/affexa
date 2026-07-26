<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rolls a price_history partition up into price_history_monthly_agg.
 *
 * Always run before a partition is retired. Once the detailed rows are gone the
 * only way to answer "was this ever cheaper than today" is these aggregates, so
 * dropping a partition without consolidating it first silently destroys the
 * baseline the whole scoring engine depends on.
 */
final class PriceHistoryConsolidator
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * @return int number of aggregate rows written or refreshed
     */
    public function consolidate(string $partition): int
    {
        if (preg_match('/^price_history_\d{4}_\d{2}$/', $partition) !== 1) {
            throw new InvalidArgumentException("[{$partition}] is not a price_history partition.");
        }

        /*
         * The EXISTS guard skips rows whose product has since been deleted:
         * price_history has no foreign key (see its migration) but the aggregate
         * table does, so an orphan would abort the whole statement.
         *
         * ON CONFLICT makes the command safe to re-run over a partition that was
         * partially consolidated before a failure.
         */
        return $this->connection()->affectingStatement(<<<SQL
            INSERT INTO price_history_monthly_agg
                (product_id, marketplace_id, month, min_price, max_price, avg_price, median_price, samples)
            SELECT
                ph.product_id,
                min(ph.marketplace_id),
                date_trunc('month', ph.collected_at)::date,
                min(ph.price),
                max(ph.price),
                round(avg(ph.price), 2),
                -- Cast required: percentile_cont returns double precision.
                round((percentile_cont(0.5) WITHIN GROUP (ORDER BY ph.price))::numeric, 2),
                count(*)
            FROM {$partition} ph
            WHERE EXISTS (SELECT 1 FROM products p WHERE p.id = ph.product_id)
            GROUP BY ph.product_id, date_trunc('month', ph.collected_at)::date
            ON CONFLICT (product_id, month) DO UPDATE SET
                min_price    = LEAST(price_history_monthly_agg.min_price, excluded.min_price),
                max_price    = GREATEST(price_history_monthly_agg.max_price, excluded.max_price),
                avg_price    = excluded.avg_price,
                median_price = excluded.median_price,
                samples      = excluded.samples,
                updated_at   = now()
        SQL);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
