<?php

declare(strict_types=1);

use App\Application\Actions\EvaluatePromotionAction;
use App\Domain\Promotion\PromotionConfidence;
use App\Domain\Promotion\PromotionStatus;
use App\Domain\Promotion\RejectionReason;
use App\Infrastructure\Database\PartitionedTable;
use App\Infrastructure\Database\PartitionManager;
use App\Jobs\EvaluatePromotionJob;
use App\Models\Marketplace;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Promotion;
use App\Settings\ScoringSettings;
use Carbon\CarbonImmutable;

beforeEach(function () {
    (new PartitionManager)->ensure(
        PartitionedTable::PriceHistory,
        CarbonImmutable::now()->subMonths(7),
        periodsAhead: 7,
    );

    $this->marketplace = Marketplace::factory()->trusted()->create();
});

/**
 * Seeds a product plus a deep, dense history so confidence comes out High unless a
 * test deliberately arranges otherwise.
 */
function evaluableProduct(Marketplace $marketplace, string $current, string $previous, string $historicalPrice = '100.00'): Product
{
    $product = Product::factory()
        ->for($marketplace)
        ->pricedAt($current, $previous)
        ->create(['rating' => 4.8, 'reviews_count' => 1200, 'is_prime' => true, 'has_free_shipping' => true]);

    for ($sample = 0; $sample < 35; $sample++) {
        PriceHistory::factory()->for($product)
            ->at(CarbonImmutable::now()->subDays($sample % 29))
            ->pricedAt($historicalPrice)
            ->create();
    }

    PriceHistory::factory()->for($product)
        ->at(CarbonImmutable::now()->subDays(120))
        ->pricedAt($historicalPrice)
        ->create();

    return $product->refresh();
}

it('records a pending promotion for a genuine discount', function () {
    $product = evaluableProduct($this->marketplace, '50.00', '100.00');

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    $promotion = Promotion::first();

    expect($promotion)->not->toBeNull()
        ->and($promotion->product_id)->toBe($product->id)
        ->and($promotion->confidence)->toBe(PromotionConfidence::High)
        ->and($promotion->score)->toBeGreaterThan(50)
        ->and($promotion->status)->toBeIn([PromotionStatus::Pending, PromotionStatus::Approved]);
});

it('persists the full breakdown, not merely the score', function () {
    $product = evaluableProduct($this->marketplace, '50.00', '100.00');

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    $breakdown = Promotion::first()?->score_breakdown;

    // Without this, changing the weights later is guesswork rather than analysis.
    expect($breakdown['rules'])->toHaveKeys([
        'discount_threshold', 'below_historical_median', 'rating',
        'review_volume', 'prime', 'free_shipping', 'marketplace_trust', 'all_time_low',
    ])
        ->and($breakdown['rules']['discount_threshold']['observed'])->toBe(50)
        // jsonb normalises 115.0 to 115, so the comparison is by value.
        ->and($breakdown['total_possible'])->toEqual(115);
});

it('rejects a discount below the configured minimum', function () {
    $product = evaluableProduct($this->marketplace, '95.00', '100.00');

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    $promotion = Promotion::first();

    expect($promotion?->status)->toBe(PromotionStatus::Rejected)
        ->and($promotion?->rejection_reason)->toBe(RejectionReason::BelowMinimumDiscount);
});

it('rejects a price that is not actually below its own history', function () {
    // A big "discount" against an inflated previous price, but the product has
    // been selling at 40 all along.
    $product = evaluableProduct($this->marketplace, '60.00', '200.00', historicalPrice: '40.00');

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    expect(Promotion::first()?->rejection_reason)->toBe(RejectionReason::NotBelowHistoricalMedian);
});

it('rejects a product with no history at all', function () {
    $product = Product::factory()->for($this->marketplace)->pricedAt('50.00', '100.00')->create();

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    expect(Promotion::first()?->rejection_reason)->toBe(RejectionReason::InsufficientHistory);
});

it('rejects an out of stock product without scoring it', function () {
    $product = evaluableProduct($this->marketplace, '50.00', '100.00');
    $product->update(['in_stock' => false]);

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    expect(Promotion::first()?->rejection_reason)->toBe(RejectionReason::OutOfStock);
});

it('never approves automatically on low confidence, however high the score', function () {
    $product = Product::factory()->for($this->marketplace)->pricedAt('10.00', '100.00')->create([
        'rating' => 5.0, 'reviews_count' => 9000, 'is_prime' => true, 'has_free_shipping' => true,
    ]);

    // Three samples over three days: a 90% discount computed from this is not
    // evidence of anything.
    foreach ([1, 2, 3] as $daysAgo) {
        PriceHistory::factory()->for($product)->at(CarbonImmutable::now()->subDays($daysAgo))->pricedAt('100.00')->create();
    }

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    $promotion = Promotion::first();

    expect($promotion?->confidence)->toBe(PromotionConfidence::Low)
        ->and($promotion?->status)->toBe(PromotionStatus::Pending)
        ->and($promotion?->status)->not->toBe(PromotionStatus::Approved);
});

it('approves automatically when the score clears the bar on solid history', function () {
    app(ScoringSettings::class)->fill(['minimum_score_for_automatic_publication' => 60])->save();

    $product = evaluableProduct($this->marketplace, '30.00', '100.00');

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    $promotion = Promotion::first();

    expect($promotion?->confidence)->toBe(PromotionConfidence::High)
        ->and($promotion?->status)->toBe(PromotionStatus::Approved)
        ->and($promotion?->approved_at)->not->toBeNull();
});

it('respects a reweighting made in settings without any code change', function () {
    app(ScoringSettings::class)->fill(['minimum_discount_percent' => 60])->save();

    $product = evaluableProduct($this->marketplace, '50.00', '100.00');

    (new EvaluatePromotionJob($product->id))->handle(app(EvaluatePromotionAction::class));

    // A 50% discount cleared the default 20% bar; against a 60% bar it does not.
    expect(Promotion::first()?->rejection_reason)->toBe(RejectionReason::BelowMinimumDiscount);
});

it('refuses to open a second live promotion for the same deal', function () {
    $product = evaluableProduct($this->marketplace, '50.00', '100.00');
    $action = app(EvaluatePromotionAction::class);

    $action->execute($product);
    $second = $action->execute($product->refresh());

    // The partial unique index on dedupe_hash arbitrates; losing the race is an
    // ordinary outcome of two workers, not a failure.
    expect($second)->toBeNull()
        ->and(Promotion::where('product_id', $product->id)->whereNot('status', PromotionStatus::Rejected)->count())->toBe(1);
});

it('is unique per product so a capture and a revalidation do not both score it', function () {
    $job = new EvaluatePromotionJob(42);

    expect($job->uniqueId())->toBe('evaluate-promotion:42')
        ->and($job->queue)->toBe('evaluate');
});

it('tolerates a product deleted between capture and evaluation', function () {
    (new EvaluatePromotionJob(999_999))->handle(app(EvaluatePromotionAction::class));

    expect(Promotion::count())->toBe(0);
});
