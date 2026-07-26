<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * slug is citext so that "Amazon" and "amazon" cannot both be registered;
         * case folding belongs in the type rather than in every query.
         *
         * consecutive_failures, circuit_open_until and last_error_* implement the
         * circuit breaker: a marketplace whose API is down must stop consuming
         * queue capacity instead of failing thousands of jobs.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE marketplaces (
                id                      bigserial PRIMARY KEY,
                public_id               uuid NOT NULL DEFAULT gen_random_uuid(),
                slug                    citext NOT NULL,
                name                    text NOT NULL,
                connector               text NOT NULL,
                is_active               boolean NOT NULL DEFAULT true,
                trust_score             smallint NOT NULL DEFAULT 50,
                fetch_interval_minutes  smallint NOT NULL DEFAULT 60,
                rate_limit_per_minute   smallint NOT NULL DEFAULT 60,
                credentials             text,
                settings                jsonb NOT NULL DEFAULT '{}'::jsonb,
                last_fetched_at         timestamptz,
                last_error_at           timestamptz,
                last_error_message      text,
                consecutive_failures    smallint NOT NULL DEFAULT 0,
                circuit_open_until      timestamptz,
                created_at              timestamptz NOT NULL DEFAULT now(),
                updated_at              timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_marketplaces_slug UNIQUE (slug),
                CONSTRAINT uq_marketplaces_public_id UNIQUE (public_id),
                CONSTRAINT chk_marketplaces_trust_score
                    CHECK (trust_score BETWEEN 0 AND 100),
                CONSTRAINT chk_marketplaces_fetch_interval
                    CHECK (fetch_interval_minutes > 0),
                CONSTRAINT chk_marketplaces_rate_limit
                    CHECK (rate_limit_per_minute > 0),
                CONSTRAINT chk_marketplaces_consecutive_failures
                    CHECK (consecutive_failures >= 0)
            );

            COMMENT ON COLUMN marketplaces.connector IS
                'Fully qualified class implementing MarketplaceConnector; resolved by lookup, never by a hardcoded match.';
            COMMENT ON COLUMN marketplaces.credentials IS
                'Encrypted by the Laravel cast. The key stays outside the database so a dump alone cannot decrypt it.';
            COMMENT ON COLUMN marketplaces.circuit_open_until IS
                'While in the future the scheduler skips this marketplace entirely.';

            -- The scheduler asks for active marketplaces ordered by staleness on
            -- every tick; this covers that access path.
            CREATE INDEX idx_marketplaces_active_last_fetched
                ON marketplaces (is_active, last_fetched_at NULLS FIRST);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS marketplaces CASCADE;');
    }
};
