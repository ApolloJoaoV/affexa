<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Tokens live apart from marketplaces because they rotate on their own
         * cycle and carry far more writes than the parent row.
         *
         * A refresh never overwrites: it inserts a new row and stamps rotated_at
         * on the old one, so an audit can reconstruct which credential was in use
         * when a call failed.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE marketplace_tokens (
                id              bigserial PRIMARY KEY,
                marketplace_id  bigint NOT NULL REFERENCES marketplaces(id) ON DELETE CASCADE,
                type            varchar(20) NOT NULL,
                value           text NOT NULL,
                expires_at      timestamptz,
                rotated_at      timestamptz,
                metadata        jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT chk_marketplace_tokens_type
                    CHECK (type IN ('access', 'refresh'))
            );

            COMMENT ON COLUMN marketplace_tokens.value IS
                'Encrypted by the Laravel cast.';
            COMMENT ON COLUMN marketplace_tokens.rotated_at IS
                'Set when superseded. NULL means this is the current token of its type.';

            /*
             * The specification called for a partial index on "expires_at > now()",
             * which PostgreSQL rejects: an index predicate must be IMMUTABLE and
             * now() is not, so the index would have to be rebuilt continuously.
             *
             * rotated_at IS NULL expresses the same intent immutably — there is
             * exactly one current token per marketplace and type, and the lookup
             * never walks the rotated history.
             */
            CREATE UNIQUE INDEX idx_marketplace_tokens_current
                ON marketplace_tokens (marketplace_id, type)
                WHERE rotated_at IS NULL;

            -- Supports the preemptive refresh sweep, which looks for tokens
            -- expiring inside the next couple of hours.
            CREATE INDEX idx_marketplace_tokens_expiry
                ON marketplace_tokens (expires_at)
                WHERE rotated_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS marketplace_tokens CASCADE;');
    }
};
