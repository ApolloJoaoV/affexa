<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE publications (
                id                   bigserial PRIMARY KEY,
                public_id            uuid NOT NULL DEFAULT gen_random_uuid(),
                promotion_id         bigint NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
                channel              varchar(30) NOT NULL,
                provider             varchar(30),
                recipient            text,
                status               varchar(20) NOT NULL DEFAULT 'pending',
                message_body         text,
                card_path            text,
                provider_message_id  text,
                attempts             smallint NOT NULL DEFAULT 0,
                error_code           varchar(60),
                error_message        text,
                scheduled_for        timestamptz,
                sent_at              timestamptz,
                delivered_at         timestamptz,
                created_at           timestamptz NOT NULL DEFAULT now(),
                updated_at           timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_publications_public_id UNIQUE (public_id),
                CONSTRAINT chk_publications_channel
                    CHECK (channel IN ('whatsapp', 'telegram', 'instagram', 'facebook', 'x')),
                CONSTRAINT chk_publications_status
                    CHECK (status IN ('pending', 'scheduled', 'sending', 'sent', 'delivered', 'failed', 'cancelled')),
                CONSTRAINT chk_publications_attempts CHECK (attempts >= 0),
                CONSTRAINT chk_publications_sent_has_timestamp
                    CHECK (status NOT IN ('sent', 'delivered') OR sent_at IS NOT NULL)
            );

            COMMENT ON COLUMN publications.provider IS
                'Which implementation actually sent it, e.g. meta or twilio for WhatsApp.';
            COMMENT ON COLUMN publications.error_code IS
                'Provider error code. Used to decide whether a failure is retryable or permanent.';

            -- The queue of things waiting to go out, including sends deferred to
            -- the next allowed publishing window.
            CREATE INDEX idx_publications_due
                ON publications (scheduled_for)
                WHERE status IN ('pending', 'scheduled');

            CREATE INDEX idx_publications_promotion ON publications (promotion_id);

            CREATE INDEX idx_publications_channel_sent
                ON publications (channel, sent_at DESC);

            -- Feeds the failure rate alert without scanning successful sends.
            CREATE INDEX idx_publications_failed
                ON publications (created_at DESC)
                WHERE status = 'failed';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS publications CASCADE;');
    }
};
