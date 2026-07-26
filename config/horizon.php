<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    /*
     * Thresholds for the LongWaitDetected alert, per queue rather than global.
     *
     * A capture waiting five minutes is unremarkable; a publication waiting five
     * minutes means subscribers are getting stale prices, so it is alerted on far
     * sooner.
     */
    'waits' => [
        'redis:default' => 60,
        'redis_long:fetch' => 600,
        'redis:process' => 300,
        'redis:evaluate' => 300,
        'redis:message' => 120,
        'redis:card' => 180,
        'redis:publish' => 60,
        'redis:history' => 600,
        'redis:maintenance' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
     * One supervisor per stage of the pipeline, because the stages have nothing in
     * common operationally: capture is bound by a marketplace's rate limit,
     * processing by the database, card rendering by memory, and publishing by
     * WhatsApp's own throughput.
     *
     * A single supervisor with all queues would let a burst of card rendering
     * starve publishing, and Horizon does not honour queue order under
     * balance: auto — separate named supervisors are the documented way to
     * express priority.
     *
     * Every supervisor's timeout sits above its jobs' own timeout and below the
     * connection's retry_after, so a job is never retried while still running.
     */
    'defaults' => [
        /*
         * Capture. Low concurrency on purpose: parallelism here does not help,
         * because the token bucket, not the worker count, decides how fast a
         * marketplace can be called.
         */
        'supervisor-fetch' => [
            'connection' => 'redis_long',
            'queue' => ['fetch'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 660,
            'nice' => 10,
        ],

        /*
         * Persistence of captured batches, and the history writes. The widest pool:
         * this is where the daily volume actually lands.
         */
        'supervisor-process' => [
            'connection' => 'redis',
            'queue' => ['process', 'history'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 2,
            'maxProcesses' => 12,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 150,
            'nice' => 5,
        ],

        'supervisor-evaluate' => [
            'connection' => 'redis',
            'queue' => ['evaluate'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 6,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 5,
        ],

        /*
         * Message rendering and card generation. Imagick is the memory hog of the
         * system, hence the far larger allowance and the modest process cap.
         */
        'supervisor-content' => [
            'connection' => 'redis',
            'queue' => ['message', 'card'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 768,
            'tries' => 3,
            'timeout' => 150,
            'nice' => 10,
        ],

        /*
         * Publishing. Deliberately near-serial: the bottleneck is the WhatsApp
         * Business API's rate, not CPU, and extra workers would only convert into
         * provider rejections.
         *
         * balance is false so the pool stays exactly this size, and its nice value
         * is the lowest in the system so this queue is never starved by the bulk
         * stages. It is 0 rather than negative because only root may raise a
         * process's priority: the ordering is achieved by deprioritising the
         * others instead.
         */
        'supervisor-publish' => [
            'connection' => 'redis',
            'queue' => ['publish'],
            'balance' => false,
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 120,
            'nice' => 0,
        ],

        'supervisor-maintenance' => [
            'connection' => 'redis',
            'queue' => ['maintenance', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 2,
            'timeout' => 120,
            'nice' => 15,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-fetch' => ['maxProcesses' => 4, 'balanceMaxShift' => 1, 'balanceCooldown' => 5],
            'supervisor-process' => ['maxProcesses' => 24, 'balanceMaxShift' => 2, 'balanceCooldown' => 3],
            'supervisor-evaluate' => ['maxProcesses' => 12, 'balanceMaxShift' => 2, 'balanceCooldown' => 3],
            'supervisor-content' => ['maxProcesses' => 6, 'balanceMaxShift' => 1, 'balanceCooldown' => 5],
            'supervisor-publish' => ['maxProcesses' => 2],
            'supervisor-maintenance' => ['maxProcesses' => 3],
        ],

        'local' => [
            'supervisor-fetch' => ['maxProcesses' => 1],
            'supervisor-process' => ['maxProcesses' => 3],
            'supervisor-evaluate' => ['maxProcesses' => 2],
            'supervisor-content' => ['maxProcesses' => 1],
            'supervisor-publish' => ['maxProcesses' => 1],
            'supervisor-maintenance' => ['maxProcesses' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
