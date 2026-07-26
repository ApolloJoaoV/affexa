<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('applies the configured session timeouts on the primary connection', function () {
    // Set on connect by ConfigureConnectionTimeouts: a runaway query must not be
    // able to hold a pooled connection open indefinitely.
    expect(DB::scalar('SHOW statement_timeout'))->toBe('30s')
        ->and(DB::scalar('SHOW lock_timeout'))->toBe('5s')
        ->and(DB::scalar('SHOW idle_in_transaction_session_timeout'))->toBe('1min');
});

it('gives queue workers a more generous statement timeout', function () {
    config()->set('database.connections.pgsql_worker.database', config('database.connections.pgsql.database'));

    $worker = DB::connection('pgsql_worker');

    // Batched price_history inserts legitimately outlive any web request.
    expect($worker->scalar('SHOW statement_timeout'))->toBe('2min')
        ->and($worker->scalar('SHOW lock_timeout'))->toBe('15s');

    $worker->disconnect();
});

it('declares a read and write split on the primary connection', function () {
    $connection = config('database.connections.pgsql');

    expect($connection)->toHaveKeys(['read', 'write'])
        ->and($connection['sticky'])->toBeTrue();
});

it('does not run the suite against sqlite', function () {
    // §23: SQLite has no generated columns, tstzrange or partitioning, so a green
    // suite on SQLite would prove nothing about this schema.
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});
