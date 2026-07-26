<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled maintenance
|--------------------------------------------------------------------------
|
| The capture command runs every minute but dispatches only the marketplaces
| whose own fetch_interval_minutes has elapsed. Scheduling one entry per
| marketplace instead would mean querying the table while building the schedule —
| which happens on every artisan invocation — and would need a deploy to change an
| interval.
|
| Every entry is guarded by withoutOverlapping with an explicit expiry, so a stuck
| run eventually releases its lock instead of silently disabling the schedule, and
| by onOneServer so a multi-node deployment does not duplicate the work.
|
*/

Schedule::command('promohub:marketplaces:fetch')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

Schedule::command('promohub:tokens:refresh')
    ->everyThirtyMinutes()
    ->withoutOverlapping(25)
    ->onOneServer();

/*
 * Partitions are provisioned well ahead of need: a missing partition is not a
 * slow query, it is a failed insert for every row in that period.
 */
Schedule::command('promohub:partitions:ensure')
    ->dailyAt('03:10')
    ->withoutOverlapping(60)
    ->onOneServer();

/*
 * Horizon's metrics dashboard stays empty without this: running the Horizon
 * process alone records no snapshots.
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('promohub:partitions:prune')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping(120)
    ->onOneServer();
