<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long detailed data is kept before its partitions are retired. Detailed
    | price history is consolidated into price_history_monthly_agg before being
    | dropped, so long term statistics outlive the raw rows.
    |
    | These are operational floors. The administrator facing RetentionSettings
    | will read from here as its default.
    |
    */

    'retention' => [
        'price_history_months' => (int) env('PROMOHUB_RETENTION_PRICE_HISTORY_MONTHS', 12),
        'api_call_logs_days' => (int) env('PROMOHUB_RETENTION_API_CALL_LOGS_DAYS', 21),

        /*
         * When false, prune only detaches partitions and leaves the tables in
         * place for a human to inspect and drop. Safer default for production:
         * a detached partition is invisible to the application but recoverable.
         */
        'drop_after_detach' => (bool) env('PROMOHUB_RETENTION_DROP_AFTER_DETACH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After this many consecutive failures a marketplace is held out of rotation
    | for the cooldown, and the scheduler skips it entirely. This exists so that
    | one dead API cannot consume the whole queue with jobs that are certain to
    | fail.
    |
    */

    'circuit_breaker' => [
        'failure_threshold' => (int) env('PROMOHUB_CIRCUIT_FAILURE_THRESHOLD', 5),
        'cooldown_minutes' => (int) env('PROMOHUB_CIRCUIT_COOLDOWN_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP
    |--------------------------------------------------------------------------
    |
    | Applies to every marketplace call. Retries cover transient failures only:
    | connection errors, 429 and 5xx. A 4xx is a request we got wrong and retrying
    | it just wastes the rate limit.
    |
    */

    'http' => [
        'timeout_seconds' => (int) env('PROMOHUB_HTTP_TIMEOUT', 15),
        'connect_timeout_seconds' => (int) env('PROMOHUB_HTTP_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('PROMOHUB_HTTP_RETRIES', 3),
        'retry_base_delay_ms' => (int) env('PROMOHUB_HTTP_RETRY_DELAY_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    |
    | Access tokens are renewed this far ahead of expiry, so a fetch run is never
    | interrupted by a token dying mid-pagination.
    |
    */

    'tokens' => [
        'refresh_ahead_minutes' => (int) env('PROMOHUB_TOKEN_REFRESH_AHEAD_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | heartbeat_hours: an unchanged price is recorded again only after this long,
    | so the history stays sampled rather than duplicated. Every read would inflate
    | the biggest table in the system by an order of magnitude for no analytical
    | gain.
    |
    | batch_size: products handed to one ProcessProductBatchJob. Large enough that
    | a catalogue does not become a job per product, small enough that a failure
    | retries little work.
    |
    */

    'pricing' => [
        'heartbeat_hours' => (int) env('PROMOHUB_PRICE_HEARTBEAT_HOURS', 6),
    ],

    'pipeline' => [
        'batch_size' => (int) env('PROMOHUB_PIPELINE_BATCH_SIZE', 100),
        'max_items_per_fetch' => (int) env('PROMOHUB_MAX_ITEMS_PER_FETCH', 2000),
    ],

];
