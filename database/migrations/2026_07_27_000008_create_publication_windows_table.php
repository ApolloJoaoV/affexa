<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Enforces "do not republish the same product on the same channel before
         * the configured interval" in the database rather than in application code.
         *
         * An application level check cannot make this guarantee: two workers can
         * both read "no recent publication" and both proceed. The exclusion
         * constraint makes the second insert fail, whatever the interleaving, and
         * the publish path treats that failure as a duplicate rejection rather
         * than an error.
         *
         * GiST is required because the constraint mixes equality on scalars with
         * overlap on a range, which is exactly what btree_gist provides.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE publication_windows (
                id           bigserial PRIMARY KEY,
                product_id   bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                channel      varchar(30) NOT NULL,
                "window"     tstzrange NOT NULL,
                promotion_id bigint REFERENCES promotions(id) ON DELETE SET NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT chk_publication_windows_channel
                    CHECK (channel IN ('whatsapp', 'telegram', 'instagram', 'facebook', 'x')),
                CONSTRAINT chk_publication_windows_window_not_empty
                    CHECK (NOT isempty("window")),
                CONSTRAINT excl_publication_windows_no_overlap
                    EXCLUDE USING gist (product_id WITH =, channel WITH =, "window" WITH &&)
            );

            COMMENT ON COLUMN publication_windows."window" IS
                'tstzrange from the moment of publication to the end of the cooldown. Quoted because window is a reserved word.';

            CREATE INDEX idx_publication_windows_promotion
                ON publication_windows (promotion_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS publication_windows CASCADE;');
    }
};
