<?php

declare(strict_types=1);

use App\Infrastructure\Database\PartitionedTable;
use App\Infrastructure\Database\PartitionManager;
use App\Infrastructure\Database\PriceHistoryConsolidator;
use App\Models\PriceHistory;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->partitions = new PartitionManager;
    $this->product = Product::factory()->create();

    // A partition well past any retention window, with a known price series.
    $this->expiredMonth = CarbonImmutable::now()->subMonths(14)->startOfMonth();
    $this->partitions->ensure(PartitionedTable::PriceHistory, $this->expiredMonth, periodsAhead: 0);
    $this->expiredPartition = 'price_history_'.$this->expiredMonth->format('Y_m');

    foreach (['100.00', '80.00', '120.00', '90.00'] as $offset => $price) {
        PriceHistory::factory()
            ->for($this->product)
            ->at($this->expiredMonth->addDays($offset + 1))
            ->pricedAt($price)
            ->create();
    }
});

it('consolidates a partition into monthly aggregates before it is retired', function () {
    $written = (new PriceHistoryConsolidator)->consolidate($this->expiredPartition);

    $aggregate = DB::table('price_history_monthly_agg')
        ->where('product_id', $this->product->id)
        ->first();

    expect($written)->toBe(1)
        ->and($aggregate->min_price)->toBe('80.00')
        ->and($aggregate->max_price)->toBe('120.00')
        ->and($aggregate->avg_price)->toBe('97.50')
        ->and($aggregate->median_price)->toBe('95.00')
        ->and((int) $aggregate->samples)->toBe(4);
});

it('can be re-run over the same partition without duplicating aggregates', function () {
    $consolidator = new PriceHistoryConsolidator;

    $consolidator->consolidate($this->expiredPartition);
    $consolidator->consolidate($this->expiredPartition);

    expect(DB::table('price_history_monthly_agg')->where('product_id', $this->product->id)->count())->toBe(1);
});

it('skips observations whose product has since been deleted', function () {
    // price_history has no foreign key by design, so an orphan is possible; the
    // aggregate table does have one, and must not abort the whole statement.
    $this->product->forceDelete();

    expect((new PriceHistoryConsolidator)->consolidate($this->expiredPartition))->toBe(0);
});

it('identifies partitions past the retention window', function () {
    $expired = array_column(
        $this->partitions->partitionsOlderThan(
            PartitionedTable::PriceHistory,
            CarbonImmutable::now()->startOfMonth()->subMonths(12)
        ),
        'name'
    );

    expect($expired)->toContain($this->expiredPartition)
        ->and($expired)->not->toContain('price_history_'.CarbonImmutable::now()->format('Y_m'));
});

it('detaches a retired partition so it is invisible through the parent', function () {
    expect(PriceHistory::where('product_id', $this->product->id)->count())->toBe(4);

    $this->partitions->detach(PartitionedTable::PriceHistory, $this->expiredPartition);

    expect(PriceHistory::where('product_id', $this->product->id)->count())->toBe(0)
        // Detached, not destroyed: the table survives for inspection or recovery.
        ->and($this->partitions->partitionExists($this->expiredPartition))->toBeTrue();
});

it('drops the partition when asked to', function () {
    $this->partitions->detach(PartitionedTable::PriceHistory, $this->expiredPartition, drop: true);

    expect($this->partitions->partitionExists($this->expiredPartition))->toBeFalse();
});

it('preserves long term statistics after the detailed rows are gone', function () {
    (new PriceHistoryConsolidator)->consolidate($this->expiredPartition);
    $this->partitions->detach(PartitionedTable::PriceHistory, $this->expiredPartition, drop: true);

    $cheapestEver = DB::table('price_history_monthly_agg')
        ->where('product_id', $this->product->id)
        ->min('min_price');

    // The whole point of consolidating first: "was it ever cheaper" survives.
    expect($cheapestEver)->toBe('80.00')
        ->and(PriceHistory::where('product_id', $this->product->id)->count())->toBe(0);
});

it('refuses to consolidate a table that is not a price history partition', function () {
    (new PriceHistoryConsolidator)->consolidate('products');
})->throws(InvalidArgumentException::class);

it('refuses to detach a table that is not a partition of the given parent', function () {
    $this->partitions->detach(PartitionedTable::PriceHistory, 'products');
})->throws(InvalidArgumentException::class);

it('reports what it would retire without changing anything in a dry run', function () {
    $this->artisan('promohub:partitions:prune', ['--dry-run' => true])
        ->expectsOutputToContain($this->expiredPartition)
        ->assertSuccessful();

    expect($this->partitions->partitionExists($this->expiredPartition))->toBeTrue()
        ->and(DB::table('price_history_monthly_agg')->count())->toBe(0);
});

it('consolidates and retires expired partitions end to end', function () {
    $this->artisan('promohub:partitions:prune', ['--drop' => true])->assertSuccessful();

    expect($this->partitions->partitionExists($this->expiredPartition))->toBeFalse()
        ->and(DB::table('price_history_monthly_agg')->where('product_id', $this->product->id)->count())->toBe(1);
});
