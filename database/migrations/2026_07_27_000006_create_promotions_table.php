<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A promotion is the evaluation of a product at one instant, kept apart
         * from the sending of it. That separation is what lets the same promotion
         * go to WhatsApp today and Telegram tomorrow without being re-scored.
         *
         * The status and confidence CHECK constraints mirror the PromotionStatus,
         * PromotionConfidence and RejectionReason enums; a test asserts the two
         * stay in agreement.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE promotions (
                id                bigserial PRIMARY KEY,
                public_id         uuid NOT NULL DEFAULT gen_random_uuid(),
                product_id        bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                marketplace_id    bigint NOT NULL REFERENCES marketplaces(id) ON DELETE CASCADE,
                price             money_brl NOT NULL,
                previous_price    money_brl,
                discount_percent  smallint NOT NULL DEFAULT 0,
                score             smallint NOT NULL DEFAULT 0,
                score_breakdown   jsonb NOT NULL DEFAULT '{}'::jsonb,
                confidence        varchar(10) NOT NULL,
                status            varchar(20) NOT NULL DEFAULT 'pending',
                rejection_reason  varchar(40),
                evaluated_at      timestamptz NOT NULL DEFAULT now(),
                approved_at       timestamptz,
                published_at      timestamptz,
                expires_at        timestamptz,
                dedupe_hash       bytea NOT NULL,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_promotions_public_id UNIQUE (public_id),
                CONSTRAINT chk_promotions_score CHECK (score BETWEEN 0 AND 100),
                CONSTRAINT chk_promotions_discount_percent
                    CHECK (discount_percent BETWEEN -100 AND 100),
                CONSTRAINT chk_promotions_confidence
                    CHECK (confidence IN ('low', 'medium', 'high')),
                CONSTRAINT chk_promotions_status
                    CHECK (status IN ('pending', 'approved', 'rejected', 'published', 'expired', 'failed')),
                CONSTRAINT chk_promotions_rejection_reason
                    CHECK (rejection_reason IS NULL OR rejection_reason IN (
                        'below_minimum_discount', 'below_minimum_score', 'not_below_historical_median',
                        'insufficient_history', 'duplicate_within_window', 'category_inactive', 'out_of_stock'
                    )),
                -- A rejection without a reason is unusable when tuning the weights.
                CONSTRAINT chk_promotions_rejected_has_reason
                    CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL),
                CONSTRAINT chk_promotions_published_has_timestamp
                    CHECK (status <> 'published' OR published_at IS NOT NULL)
            );

            COMMENT ON COLUMN promotions.score_breakdown IS
                'Per rule points and observed values. Mandatory: without it, retuning the scoring weights is guesswork.';
            COMMENT ON COLUMN promotions.dedupe_hash IS
                'Identifies the same deal across evaluations, so a repeated capture does not produce a second promotion.';

            -- The approval queue screen. Partial, so it stays the size of the
            -- backlog rather than the size of all history.
            CREATE INDEX idx_promotions_pending
                ON promotions (score DESC, evaluated_at DESC)
                WHERE status = 'pending';

            CREATE INDEX idx_promotions_published
                ON promotions (published_at DESC)
                WHERE status = 'published';

            /*
             * Deduplication.
             *
             * The specification asked for a unique index on dedupe_hash "limited
             * to a window", which an index cannot express: a predicate has to be
             * IMMUTABLE and any time window needs now(). The time based half of
             * the rule is enforced by publication_windows instead.
             *
             * What is enforceable here, and what actually matters, is that a deal
             * cannot occupy two live slots at once: only one non-terminal
             * promotion may exist per dedupe_hash.
             */
            CREATE UNIQUE INDEX uq_promotions_dedupe_active
                ON promotions (dedupe_hash)
                WHERE status IN ('pending', 'approved', 'published');

            CREATE INDEX idx_promotions_product_evaluated
                ON promotions (product_id, evaluated_at DESC);

            CREATE INDEX idx_promotions_score_breakdown
                ON promotions USING gin (score_breakdown jsonb_path_ops);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS promotions CASCADE;');
    }
};
