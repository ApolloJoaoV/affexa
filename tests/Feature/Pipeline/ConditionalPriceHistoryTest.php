<?php

declare(strict_types=1);

use App\Application\Actions\ProcessProductBatchAction;
use App\Domain\Pricing\Money;
use App\Models\Marketplace;
use App\Models\PriceHistory;
use Illuminate\Support\Facades\DB;

/*
 * Recording every reading would inflate the largest table in the system by an
 * order of magnitude while telling the scoring engine nothing it did not already
 * know. These tests pin the sampling rule that prevents it.
 */

beforeEach(function () {
    $this->marketplace = Marketplace::factory()->create(['slug' => 'history-test']);
    $this->action = app(ProcessProductBatchAction::class);
});

it('records the first observation of a product', function () {
    $result = $this->action->execute($this->marketplace, [productData('A1', '100.00')]);

    expect($result->observationsRecorded)->toBe(1);
});

it('skips a repeated reading at the same price', function () {
    $this->action->execute($this->marketplace, [productData('A1', '100.00')]);
    $result = $this->action->execute($this->marketplace, [productData('A1', '100.00')]);

    expect($result->observationsRecorded)->toBe(0)
        ->and($result->observationsSkipped())->toBe(1)
        ->and(PriceHistory::count())->toBe(1);
});

it('records a reading when the price moves', function () {
    $this->action->execute($this->marketplace, [productData('A1', '100.00')]);
    $result = $this->action->execute($this->marketplace, [productData('A1', '99.99')]);

    expect($result->observationsRecorded)->toBe(1)
        ->and(PriceHistory::count())->toBe(2);
});

it('records a heartbeat once the interval has elapsed even at an unchanged price', function () {
    $this->action->execute($this->marketplace, [productData('A1', '100.00')]);

    // Age the only sample past the heartbeat window.
    DB::update("UPDATE price_history SET collected_at = now() - interval '7 hours'");

    $result = $this->action->execute($this->marketplace, [productData('A1', '100.00')]);

    // A flat price still needs periodic proof that it was observed, or the
    // sample count collapses and every promotion turns low confidence.
    expect($result->observationsRecorded)->toBe(1)
        ->and(PriceHistory::count())->toBe(2);
});

it('still skips inside the heartbeat window', function () {
    $this->action->execute($this->marketplace, [productData('A1', '100.00')]);

    DB::update("UPDATE price_history SET collected_at = now() - interval '5 hours'");

    expect($this->action->execute($this->marketplace, [productData('A1', '100.00')])->observationsRecorded)->toBe(0);
});

it('decides per product within a mixed batch', function () {
    $this->action->execute($this->marketplace, [
        productData('A1', '100.00'),
        productData('A2', '200.00'),
        productData('A3', '300.00'),
    ]);

    $result = $this->action->execute($this->marketplace, [
        productData('A1', '100.00'),  // unchanged, skipped
        productData('A2', '150.00'),  // moved, recorded
        productData('A3', '300.00'),  // unchanged, skipped
    ]);

    expect($result->observationsRecorded)->toBe(1)
        ->and($result->observationsSkipped())->toBe(2);
});

it('writes the whole batch in one statement', function () {
    $products = [];
    for ($index = 1; $index <= 50; $index++) {
        $products[] = productData("BULK-{$index}", '10.00');
    }

    DB::enableQueryLog();
    $this->action->execute($this->marketplace, $products);
    $inserts = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'insert into price_history'));
    DB::disableQueryLog();

    expect(PriceHistory::count())->toBe(50)
        ->and($inserts)->toHaveCount(1);
});

it('respects a configured heartbeat', function () {
    config()->set('promohub.pricing.heartbeat_hours', 1);

    $this->action->execute($this->marketplace, [productData('A1', '100.00')]);
    DB::update("UPDATE price_history SET collected_at = now() - interval '90 minutes'");

    expect($this->action->execute($this->marketplace, [productData('A1', '100.00')])->observationsRecorded)->toBe(1);
});

it('records the marketplace list price without letting it drive the discount', function () {
    $this->action->execute($this->marketplace, [
        productData('A1', '50.00', overrides: ['listPrice' => Money::fromNumericString('999.00')]),
    ]);

    $observation = PriceHistory::first();

    // Kept for auditing, never used as the discount baseline: marketplaces inflate
    // it routinely.
    expect($observation?->list_price?->toNumericString())->toBe('999.00')
        ->and($observation?->price->toNumericString())->toBe('50.00');
});
