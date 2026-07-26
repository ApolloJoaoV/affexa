<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates no tables. It provisions the extensions, the immutable helper
 * functions and the money domain that every later migration and index depends
 * on, and therefore must sort first (hence the 0000_ prefix).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createExtensions();
        $this->createImmutableUnaccent();
        $this->createTextSearchConfiguration();
        $this->createProductSearchVector();
        $this->createMoneyDomain();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP DOMAIN IF EXISTS money_brl;
            DROP FUNCTION IF EXISTS product_search_vector(text, text, text);
            DROP TEXT SEARCH CONFIGURATION IF EXISTS pt_unaccent;
            DROP FUNCTION IF EXISTS immutable_unaccent(text);
        SQL);

        /*
         * The extensions are intentionally left in place. They are provisioned by
         * docker/pgsql/init/00-extensions.sql as the superuser, hold no schema
         * state of ours, and dropping them on rollback would fail (or cascade
         * destructively) on any database shared with another schema.
         */
    }

    private function createExtensions(): void
    {
        /*
         * Repeated from the init script so that a database provisioned by other
         * means still migrates. Already-present extensions make these no-ops,
         * which is what allows a non-superuser application role to run them.
         */
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS pg_trgm;
            CREATE EXTENSION IF NOT EXISTS unaccent;
            CREATE EXTENSION IF NOT EXISTS btree_gin;
            CREATE EXTENSION IF NOT EXISTS btree_gist;
            CREATE EXTENSION IF NOT EXISTS pgcrypto;
            CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
            CREATE EXTENSION IF NOT EXISTS citext;
        SQL);
    }

    /**
     * The unaccent() shipped by the extension is STABLE, not IMMUTABLE, because
     * it resolves its dictionary by name at call time. PostgreSQL therefore
     * refuses it in index expressions and generated columns, which is the single
     * most common wall hit when building Portuguese text search.
     *
     * Passing the dictionary explicitly makes the call deterministic, so the
     * wrapper can legitimately be declared IMMUTABLE.
     */
    private function createImmutableUnaccent(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text)
            RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
            $$ SELECT public.unaccent('public.unaccent', $1) $$;
        SQL);
    }

    /**
     * A Portuguese configuration that strips accents before stemming, so that
     * "cafeteira" matches "cafeteíra" and "acao" matches "ação".
     *
     * CREATE TEXT SEARCH CONFIGURATION has no IF NOT EXISTS form, so existence
     * is checked explicitly to keep the migration re-runnable.
     */
    private function createTextSearchConfiguration(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_ts_config c
                    JOIN pg_namespace n ON n.oid = c.cfgnamespace
                    WHERE c.cfgname = 'pt_unaccent' AND n.nspname = 'public'
                ) THEN
                    CREATE TEXT SEARCH CONFIGURATION public.pt_unaccent (COPY = portuguese);
                    ALTER TEXT SEARCH CONFIGURATION public.pt_unaccent
                        ALTER MAPPING FOR hword, hword_part, word
                        WITH unaccent, portuguese_stem;
                END IF;
            END
            $$;
        SQL);
    }

    /**
     * Builds the weighted search document for a product. Title outranks brand,
     * brand outranks category, so ts_rank_cd orders sensibly without the caller
     * having to know the weighting.
     *
     * Must be IMMUTABLE: products.search_vector is a stored generated column.
     */
    private function createProductSearchVector(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION product_search_vector(title text, brand text, category text)
            RETURNS tsvector
            LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
            $$
              SELECT setweight(to_tsvector('public.pt_unaccent', coalesce(title, '')), 'A')
                  || setweight(to_tsvector('public.pt_unaccent', coalesce(brand, '')), 'B')
                  || setweight(to_tsvector('public.pt_unaccent', coalesce(category, '')), 'C')
            $$;
        SQL);
    }

    /**
     * Every monetary column uses this domain. Making it a domain rather than a
     * bare numeric(12,2) means a developer cannot accidentally declare a price
     * as double precision, and the non-negative check is enforced once centrally.
     */
    private function createMoneyDomain(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_type t
                    JOIN pg_namespace n ON n.oid = t.typnamespace
                    WHERE t.typname = 'money_brl' AND n.nspname = 'public'
                ) THEN
                    CREATE DOMAIN money_brl AS numeric(12,2) CHECK (VALUE >= 0);
                END IF;
            END
            $$;
        SQL);
    }
};
