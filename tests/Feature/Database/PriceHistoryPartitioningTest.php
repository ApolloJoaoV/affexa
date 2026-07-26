<?php

declare(strict_types=1);

use App\Infrastructure\Database\PartitionedTable;
use App\Infrastructure\Database\PartitionManager;
use App\Models\PriceHistory;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->partitions = new PartitionManager;
});

it('provisions the current period and several ahead', function () {
    $names = array_column($this->partitions->partitions(PartitionedTable::PriceHistory), 'name');

    $expected = collect(range(0, 3))
        ->map(fn (int $offset): string => 'price_history_'.CarbonImmutable::now()->addMonths($offset)->format('Y_m'))
        ->all();

    expect($names)->toContain(...$expected);
});

it('routes a row into the partition covering its timestamp', function () {
    $product = Product::factory()->create();

    PriceHistory::factory()->for($product)->at(CarbonImmutable::now()->startOfMonth()->addDays(3))->create();

    $partition = 'price_history_'.CarbonImmutable::now()->format('Y_m');

    expect(DB::scalar("SELECT count(*) FROM {$partition}"))->toBe(1)
        // Visible through the parent as well: partitioning is transparent to reads.
        ->and(PriceHistory::where('product_id', $product->id)->count())->toBe(1);
});

it('lets the planner discard partitions older than the queried window', function () {
    $product = Product::factory()->create();
    PriceHistory::factory()->for($product)->at(CarbonImmutable::now())->create();

    // A partition well outside any window the scoring engine asks for.
    $old = CarbonImmutable::now()->subMonths(10);
    $this->partitions->ensure(PartitionedTable::PriceHistory, $old, periodsAhead: 0);
    $oldPartition = 'price_history_'.$old->format('Y_m');

    $plan = collect(DB::select(
        "EXPLAIN SELECT min(price) FROM price_history WHERE product_id = ? AND collected_at >= now() - interval '20 days'",
        [$product->id]
    ))->pluck('QUERY PLAN')->implode("\n");

    // Pruning is the entire justification for partitioning this table. The lower
    // bound is what §9's aggregate query relies on to avoid reading years of rows.
    expect($plan)->not->toContain($oldPartition)
        ->and($plan)->toContain('price_history_'.CarbonImmutable::now()->format('Y_m'));
});

it('creates every new partition with its brin and btree indexes', function () {
    $partition = 'price_history_'.CarbonImmutable::now()->addMonths(1)->format('Y_m');

    $indexes = collect(DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', [$partition]))
        ->pluck('indexdef')
        ->implode("\n");

    expect($indexes)->toContain('brin (collected_at)')
        ->and($indexes)->toContain('pages_per_range')
        ->and($indexes)->toContain('btree (product_id, collected_at DESC)');
});

it('tunes autovacuum on each leaf partition', function () {
    $partition = 'price_history_'.CarbonImmutable::now()->format('Y_m');

    /** @var string|null $options */
    $options = DB::scalar('SELECT array_to_string(reloptions, chr(44)) FROM pg_class WHERE relname = ?', [$partition]);

    // These cannot live on the partitioned parent; PostgreSQL rejects storage
    // parameters there, so the manager applies them per partition.
    expect($options)->toContain('autovacuum_vacuum_scale_factor=0.02')
        ->and($options)->toContain('autovacuum_analyze_scale_factor=0.01');
});

it('is idempotent when asked to provision twice', function () {
    expect($this->partitions->ensure(PartitionedTable::PriceHistory))->toBe([]);
});

it('provisions weekly partitions for the telemetry table', function () {
    $names = array_column($this->partitions->partitions(PartitionedTable::ApiCallLogs), 'name');

    $thisWeek = sprintf(
        'api_call_logs_%s_w%02d',
        CarbonImmutable::now()->isoFormat('GGGG'),
        (int) CarbonImmutable::now()->isoFormat('WW')
    );

    expect($names)->toContain($thisWeek);
});

it('refuses to insert a row no partition covers', function () {
    $product = Product::factory()->create();

    // Ten years out, deliberately beyond any provisioned partition.
    DB::insert(
        'INSERT INTO price_history (product_id, marketplace_id, price, collected_at) VALUES (?, ?, ?, ?)',
        [$product->id, $product->marketplace_id, '10.00', CarbonImmutable::now()->addYears(10)->toDateTimeString()]
    );
})->throws(QueryException::class, 'no partition of relation');
