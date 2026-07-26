<?php

declare(strict_types=1);

use App\Domain\Promotion\PromotionConfidence;
use App\Infrastructure\Database\PartitionedTable;
use App\Infrastructure\Database\PartitionManager;
use App\Infrastructure\Persistence\PriceHistoryRepository;
use App\Models\PriceHistory;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->repository = new PriceHistoryRepository;
    $this->product = Product::factory()->create();

    // History deliberately spans several months so the aggregate query is proven
    // to read across partition boundaries, not just the current one.
    (new PartitionManager)->ensure(
        PartitionedTable::PriceHistory,
        CarbonImmutable::now()->subMonths(7),
        periodsAhead: 7,
    );
});

/**
 * Seeds observations as [daysAgo, price] pairs. A list rather than a keyed map so
 * that several samples can share a day, which is what a dense history looks like.
 *
 * @param  list<array{int, string}>  $observations
 */
function seedHistory(Product $product, array $observations): void
{
    foreach ($observations as [$daysAgo, $price]) {
        PriceHistory::factory()
            ->for($product)
            ->at(CarbonImmutable::now()->subDays($daysAgo))
            ->pricedAt($price)
            ->create();
    }
}

/**
 * @return list<array{int, string}>
 */
function observations(array $pricesByDaysAgo): array
{
    $observations = [];

    foreach ($pricesByDaysAgo as $daysAgo => $price) {
        $observations[] = [(int) $daysAgo, (string) $price];
    }

    return $observations;
}

it('returns every window from a single query', function () {
    seedHistory($this->product, observations([
        5 => '90.00',    // inside 30 days
        20 => '80.00',   // inside 30 days, cheapest of that window
        45 => '70.00',   // inside 60
        80 => '60.00',   // inside 90
        150 => '50.00',  // inside 180
    ]));

    $aggregates = $this->repository->aggregatesFor($this->product->id);

    expect($aggregates->minimum30Days?->toNumericString())->toBe('80.00')
        ->and($aggregates->minimum60Days?->toNumericString())->toBe('70.00')
        ->and($aggregates->minimum90Days?->toNumericString())->toBe('60.00')
        ->and($aggregates->minimum180Days?->toNumericString())->toBe('50.00');
});

it('reads history that spans multiple monthly partitions', function () {
    seedHistory($this->product, observations([3 => '100.00', 40 => '90.00', 100 => '80.00', 170 => '70.00']));

    $touched = DB::scalar(<<<'SQL'
        SELECT count(DISTINCT tableoid::regclass::text) FROM price_history WHERE product_id = ?
    SQL, [$this->product->id]);

    expect($touched)->toBeGreaterThan(1)
        ->and($this->repository->aggregatesFor($this->product->id)->samplesTotal)->toBe(4);
});

it('computes the median rather than trusting the marketplace reference price', function () {
    seedHistory($this->product, observations([1 => '100.00', 2 => '100.00', 3 => '200.00', 4 => '400.00', 5 => '400.00']));

    $aggregates = $this->repository->aggregatesFor($this->product->id);

    // Median 200 sits far below the 240 mean; an inflated list price would have
    // produced a much friendlier baseline, which is exactly why it is not used.
    expect($aggregates->median90Days?->toNumericString())->toBe('200.00')
        ->and($aggregates->average90Days?->toNumericString())->toBe('240.00');
});

it('excludes observations older than the requested window', function () {
    seedHistory($this->product, observations([10 => '50.00', 200 => '5.00']));

    $aggregates = $this->repository->aggregatesFor($this->product->id);

    // The 200 day old bargain must not become the baseline for a 180 day window.
    expect($aggregates->minimum180Days?->toNumericString())->toBe('50.00')
        ->and($aggregates->samplesTotal)->toBe(1);
});

it('reports low confidence when there are too few recent samples', function () {
    seedHistory($this->product, observations([1 => '10.00', 2 => '10.00', 3 => '10.00']));

    $aggregates = $this->repository->aggregatesFor($this->product->id);

    expect($aggregates->samplesLast30Days)->toBe(3)
        ->and($aggregates->confidence())->toBe(PromotionConfidence::Low)
        ->and($aggregates->confidence()->allowsAutomaticPublication())->toBeFalse();
});

it('reports low confidence when the history is shallow, however dense', function () {
    // Twenty samples, but all inside the last week.
    $observations = [];
    for ($sample = 0; $sample < 20; $sample++) {
        $observations[] = [$sample % 7, '10.00'];
    }

    seedHistory($this->product, $observations);

    $aggregates = $this->repository->aggregatesFor($this->product->id);

    expect($aggregates->samplesLast30Days)->toBe(20)
        ->and($aggregates->historyDays())->toBeLessThan(30)
        ->and($aggregates->confidence())->toBe(PromotionConfidence::Low);
});

it('reports high confidence for dense, deep history', function () {
    // Thirty five samples in the last month, plus depth reaching back four months.
    $observations = [];
    for ($sample = 0; $sample < 35; $sample++) {
        $observations[] = [$sample % 29, '10.00'];
    }
    $observations[] = [120, '12.00'];

    seedHistory($this->product, $observations);

    $aggregates = $this->repository->aggregatesFor($this->product->id);

    expect($aggregates->samplesLast30Days)->toBeGreaterThan(30)
        ->and($aggregates->historyDays())->toBeGreaterThanOrEqual(60)
        ->and($aggregates->confidence())->toBe(PromotionConfidence::High);
});

it('returns an empty baseline for a product with no history', function () {
    $aggregates = $this->repository->aggregatesFor($this->product->id);

    expect($aggregates->hasHistory())->toBeFalse()
        ->and($aggregates->minimum30Days)->toBeNull()
        ->and($aggregates->confidence())->toBe(PromotionConfidence::Low);
});
