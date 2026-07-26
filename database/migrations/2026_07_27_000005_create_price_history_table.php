<?php

declare(strict_types=1);

use App\Infrastructure\Database\PartitionedTable;
use App\Infrastructure\Database\PartitionManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The highest volume table in the system, and the reason PostgreSQL was
         * chosen. Range partitioning by month keeps each index small enough to
         * stay useful at hundreds of millions of rows, and lets retention be a
         * DETACH PARTITION rather than a mass DELETE that would bloat the heap.
         *
         * The primary key must include the partition key. That is a PostgreSQL
         * requirement, not a modelling choice: a unique index cannot span
         * partitions unless it contains the partitioning column.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE price_history (
                id              bigserial,
                product_id      bigint NOT NULL,
                marketplace_id  bigint NOT NULL,
                price           money_brl NOT NULL,
                list_price      money_brl,
                in_stock        boolean NOT NULL DEFAULT true,
                source          varchar(20) NOT NULL DEFAULT 'api',
                collected_at    timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, collected_at),
                CONSTRAINT chk_price_history_source
                    CHECK (source IN ('api', 'scrape', 'manual', 'backfill'))
            ) PARTITION BY RANGE (collected_at);

            COMMENT ON TABLE price_history IS
                'Range partitioned by month. Partitions are provisioned by promohub:partitions:ensure and retired by promohub:partitions:prune.';
            COMMENT ON COLUMN price_history.list_price IS
                'The marketplace claimed reference price. Recorded for auditing but never trusted as a discount baseline; it is frequently inflated.';

            /*
             * There is deliberately no foreign key to products.
             *
             * A foreign key on a partitioned table is enforced per partition and
             * would add a row lock on products to every one of the thousands of
             * inserts per batch, for a reference that the pipeline already
             * guarantees: history rows are only ever written for a product that
             * was just upserted in the same job.
             */

            /*
             * Long term statistics survive partition retirement here. Before a
             * partition is dropped, prune consolidates it into these rows, so
             * "cheapest ever" remains answerable after the detailed data is gone.
             */
            CREATE TABLE price_history_monthly_agg (
                id             bigserial PRIMARY KEY,
                product_id     bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                marketplace_id bigint NOT NULL REFERENCES marketplaces(id) ON DELETE CASCADE,
                month          date NOT NULL,
                min_price      money_brl NOT NULL,
                max_price      money_brl NOT NULL,
                avg_price      money_brl NOT NULL,
                median_price   money_brl NOT NULL,
                samples        integer NOT NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_price_history_monthly_agg_product_month
                    UNIQUE (product_id, month),
                CONSTRAINT chk_price_history_monthly_agg_samples CHECK (samples > 0),
                CONSTRAINT chk_price_history_monthly_agg_month_is_first_day
                    CHECK (date_trunc('month', month::timestamp) = month::timestamp)
            );

            CREATE INDEX idx_price_history_monthly_agg_month
                ON price_history_monthly_agg (month DESC);
        SQL);

        /*
         * Partitions are created through the PartitionManager rather than inline
         * SQL so that the indexes and the autovacuum tuning of a partition made
         * here are identical to one made by the daily maintenance command.
         */
        (new PartitionManager)->ensure(PartitionedTable::PriceHistory);
    }

    public function down(): void
    {
        // Dropping the parent drops every attached partition with it.
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS price_history_monthly_agg CASCADE;
            DROP TABLE IF EXISTS price_history CASCADE;
        SQL);
    }
};
