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
         * Telemetry for every outbound marketplace call. Retention is short, two
         * to four weeks, so this is partitioned weekly: dropping a week is
         * instant, whereas deleting a week's rows from one large table is not.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE api_call_logs (
                id                bigserial,
                marketplace_id    bigint NOT NULL,
                endpoint          text NOT NULL,
                http_status       smallint,
                duration_ms       integer NOT NULL,
                request_hash      bytea,
                response_excerpt  text,
                occurred_at       timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at),
                CONSTRAINT chk_api_call_logs_duration CHECK (duration_ms >= 0),
                CONSTRAINT chk_api_call_logs_http_status
                    CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599)
            ) PARTITION BY RANGE (occurred_at);

            COMMENT ON COLUMN api_call_logs.response_excerpt IS
                'Truncated response body, recorded only for failures. Never the full payload.';
            COMMENT ON COLUMN api_call_logs.http_status IS
                'NULL when the request never got a response, e.g. a connection timeout.';
        SQL);

        (new PartitionManager)->ensure(PartitionedTable::ApiCallLogs);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS api_call_logs CASCADE;');
    }
};
