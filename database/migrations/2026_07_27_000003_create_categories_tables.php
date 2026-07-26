<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The hierarchy uses a materialised path in a plain text column rather
         * than ltree, so that the extension set stays exactly as provisioned in
         * the init script. Descendant lookups are prefix matches, which the
         * text_pattern_ops index below serves.
         *
         * The *_override columns let one category demand a different bar from the
         * global setting: electronics can require a score of 70 while books
         * require 50.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE categories (
                id                    bigserial PRIMARY KEY,
                public_id             uuid NOT NULL DEFAULT gen_random_uuid(),
                parent_id             bigint REFERENCES categories(id) ON DELETE CASCADE,
                name                  text NOT NULL,
                slug                  citext NOT NULL,
                path                  text NOT NULL,
                is_active             boolean NOT NULL DEFAULT true,
                min_score_override    smallint,
                min_discount_override smallint,
                created_at            timestamptz NOT NULL DEFAULT now(),
                updated_at            timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_categories_slug UNIQUE (slug),
                CONSTRAINT uq_categories_public_id UNIQUE (public_id),
                CONSTRAINT chk_categories_min_score_override
                    CHECK (min_score_override IS NULL OR min_score_override BETWEEN 0 AND 100),
                CONSTRAINT chk_categories_min_discount_override
                    CHECK (min_discount_override IS NULL OR min_discount_override BETWEEN 0 AND 100),
                CONSTRAINT chk_categories_not_own_parent
                    CHECK (parent_id IS NULL OR parent_id <> id)
            );

            COMMENT ON COLUMN categories.path IS
                'Materialised ancestry, e.g. "eletronicos/audio/fones". Descendants are a prefix match.';

            -- text_pattern_ops is required for prefix matching to use the index
            -- under a non-C collation, which this database has (ICU pt-BR).
            CREATE INDEX idx_categories_path ON categories (path text_pattern_ops);
            CREATE INDEX idx_categories_parent ON categories (parent_id);

            /*
             * Translates each marketplace's own taxonomy into ours. Without this
             * every connector would have to guess our category ids.
             */
            CREATE TABLE marketplace_category_map (
                id             bigserial PRIMARY KEY,
                marketplace_id bigint NOT NULL REFERENCES marketplaces(id) ON DELETE CASCADE,
                external_id    text NOT NULL,
                external_name  text,
                category_id    bigint REFERENCES categories(id) ON DELETE SET NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_marketplace_category_map_external
                    UNIQUE (marketplace_id, external_id)
            );

            CREATE INDEX idx_marketplace_category_map_category
                ON marketplace_category_map (category_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS marketplace_category_map CASCADE;
            DROP TABLE IF EXISTS categories CASCADE;
        SQL);
    }
};
