<?php

declare(strict_types=1);

use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceHistoryAggregates;
use App\Domain\Promotion\PromotionConfidence;
use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\Rules\AllTimeLowRule;
use App\Domain\Promotion\Scoring\Rules\BelowHistoricalMedianRule;
use App\Domain\Promotion\Scoring\Rules\DiscountThresholdRule;
use App\Domain\Promotion\Scoring\Rules\FreeShippingRule;
use App\Domain\Promotion\Scoring\Rules\MarketplaceTrustRule;
use App\Domain\Promotion\Scoring\Rules\PrimeRule;
use App\Domain\Promotion\Scoring\Rules\RatingRule;
use App\Domain\Promotion\Scoring\Rules\ReviewVolumeRule;
use App\Domain\Promotion\Scoring\ScoringEngine;
use App\Domain\Promotion\Scoring\ScoringWeights;
use Carbon\CarbonImmutable;

/*
 * The engine is a pure function of its inputs. Every test here runs without a
 * database, which is what makes retuning the weights cheap to verify.
 */

function aggregates(
    ?string $median = '100.00',
    int $samples30 = 40,
    int $historyDays = 120,
): PriceHistoryAggregates {
    return PriceHistoryAggregates::fromRow([
        'min_30' => $median,
        'min_60' => $median,
        'min_90' => $median,
        'min_180' => $median,
        'avg_90' => $median,
        'median_90' => $median,
        'samples_30' => $samples30,
        'samples_total' => $samples30 + 10,
        'history_since' => CarbonImmutable::now()->subDays($historyDays)->toDateTimeString(),
    ]);
}

function scoringContext(array $overrides = []): PromotionContext
{
    return new PromotionContext(
        productId: $overrides['productId'] ?? 1,
        currentPrice: $overrides['currentPrice'] ?? Money::fromNumericString('50.00'),
        previousPrice: array_key_exists('previousPrice', $overrides)
            ? $overrides['previousPrice']
            : Money::fromNumericString('100.00'),
        discountPercent: $overrides['discountPercent'] ?? 50,
        history: $overrides['history'] ?? aggregates(),
        marketplaceTrustScore: $overrides['marketplaceTrustScore'] ?? 100,
        rating: array_key_exists('rating', $overrides) ? $overrides['rating'] : 4.8,
        reviewsCount: $overrides['reviewsCount'] ?? 1000,
        isPrime: $overrides['isPrime'] ?? true,
        hasFreeShipping: $overrides['hasFreeShipping'] ?? true,
        inStock: $overrides['inStock'] ?? true,
        lowestPriceEver: array_key_exists('lowestPriceEver', $overrides)
            ? $overrides['lowestPriceEver']
            : Money::fromNumericString('40.00'),
    );
}

function engine(): ScoringEngine
{
    return new ScoringEngine([
        new DiscountThresholdRule,
        new BelowHistoricalMedianRule,
        new RatingRule,
        new ReviewVolumeRule,
        new PrimeRule,
        new FreeShippingRule,
        new MarketplaceTrustRule,
        new AllTimeLowRule,
    ]);
}

it('awards a perfect score when every signal is present', function () {
    $result = engine()->score(scoringContext(), new ScoringWeights);

    expect($result->score)->toBe(100)
        ->and($result->rawPoints)->toBe(115.0)
        ->and($result->totalPossible)->toBe(115.0);
});

it('scores zero when nothing qualifies', function () {
    $result = engine()->score(scoringContext([
        'currentPrice' => Money::fromNumericString('150.00'),
        'previousPrice' => Money::fromNumericString('155.00'),
        'discountPercent' => 3,
        'marketplaceTrustScore' => 0,
        'rating' => 2.0,
        'reviewsCount' => 4,
        'isPrime' => false,
        'hasFreeShipping' => false,
        'lowestPriceEver' => Money::fromNumericString('90.00'),
    ]), new ScoringWeights);

    expect($result->score)->toBe(0);
});

it('records what each rule observed, not merely its points', function () {
    $result = engine()->score(scoringContext(['discountPercent' => 37]), new ScoringWeights);

    // Without the observed value, retuning the weights later is guesswork.
    expect($result->breakdown['discount_threshold']['observed'])->toBe(37)
        ->and($result->breakdown['discount_threshold']['points'])->toBe(20.0)
        ->and($result->breakdown['rating']['observed'])->toBe(4.8)
        ->and($result->breakdown['marketplace_trust']['observed'])->toBe(100);
});

it('denies the discount points below the configured bar', function () {
    $result = engine()->score(scoringContext(['discountPercent' => 19]), new ScoringWeights);

    expect($result->pointsFor('discount_threshold'))->toBe(0.0)
        ->and($result->breakdown['discount_threshold']['reason'])->toContain('below the 20% bar');
});

it('weighs the historical median above every other single signal', function () {
    $withMedian = engine()->score(scoringContext(), new ScoringWeights);
    $aboveMedian = engine()->score(scoringContext([
        'currentPrice' => Money::fromNumericString('120.00'),
        'lowestPriceEver' => Money::fromNumericString('110.00'),
    ]), new ScoringWeights);

    // Losing the median points alone costs 40 of 115.
    expect($withMedian->score - $aboveMedian->score)->toBeGreaterThanOrEqual(34);
});

it('caps a product with no price history well below a perfect score', function () {
    $result = engine()->score(scoringContext([
        'history' => PriceHistoryAggregates::fromRow([]),
        'lowestPriceEver' => null,
    ]), new ScoringWeights);

    // The median points are unearnable, and that is deliberate: missing evidence
    // must cost, or an unknown product would outrank a proven one.
    expect($result->score)->toBe(65)
        ->and($result->breakdown['below_historical_median']['points'])->toBe(0.0)
        ->and($result->breakdown['below_historical_median']['observed'])->toBeNull();
});

it('scales marketplace trust proportionally rather than pass or fail', function () {
    $weights = new ScoringWeights;

    expect(engine()->score(scoringContext(['marketplaceTrustScore' => 50]), $weights)->pointsFor('marketplace_trust'))
        ->toBe(5.0)
        ->and(engine()->score(scoringContext(['marketplaceTrustScore' => 20]), $weights)->pointsFor('marketplace_trust'))
        ->toBe(2.0);
});

it('amplifies an all time low on deep history', function () {
    $atLow = scoringContext([
        'currentPrice' => Money::fromNumericString('40.00'),
        'lowestPriceEver' => Money::fromNumericString('40.00'),
        'rating' => 3.0,
        'isPrime' => false,
    ]);

    $result = engine()->score($atLow, new ScoringWeights);

    expect($atLow->isAllTimeLow())->toBeTrue()
        ->and($atLow->confidence())->toBe(PromotionConfidence::High)
        ->and($result->wasMultiplied())->toBeTrue()
        ->and($result->multiplier)->toBe(1.25)
        ->and($result->breakdown['all_time_low']['multiplier'])->toBe(1.25);
});

it('refuses to amplify an all time low drawn from thin history', function () {
    $result = engine()->score(scoringContext([
        'currentPrice' => Money::fromNumericString('40.00'),
        'lowestPriceEver' => Money::fromNumericString('40.00'),
        // Three samples over a week: "cheapest ever" means nothing here.
        'history' => aggregates(samples30: 3, historyDays: 7),
    ]), new ScoringWeights);

    expect($result->wasMultiplied())->toBeFalse()
        ->and($result->breakdown['all_time_low']['reason'])->toContain('too thin');
});

it('keeps the multiplier from pushing a score past one hundred', function () {
    $result = engine()->score(scoringContext([
        'currentPrice' => Money::fromNumericString('40.00'),
        'lowestPriceEver' => Money::fromNumericString('40.00'),
    ]), new ScoringWeights);

    expect($result->multiplier)->toBe(1.25)
        ->and($result->score)->toBe(100);
});

it('excludes the multiplier rule from the normalisation denominator', function () {
    // Counting it would make a perfect score mathematically unreachable.
    $result = engine()->score(scoringContext(), new ScoringWeights);

    expect($result->totalPossible)->toBe((float) (new ScoringWeights)->totalPossible())
        ->and($result->breakdown['all_time_low']['max'])->toBe(0.0);
});

it('honours reweighted rules without any code change', function () {
    $medianOnly = new ScoringWeights(
        discountThreshold: 0,
        belowHistoricalMedian: 100,
        rating: 0,
        reviewVolume: 0,
        prime: 0,
        freeShipping: 0,
        marketplaceTrust: 0,
    );

    $result = engine()->score(scoringContext(['rating' => 1.0, 'isPrime' => false, 'hasFreeShipping' => false]), $medianOnly);

    expect($result->score)->toBe(100)
        ->and($result->totalPossible)->toBe(100.0);
});

it('rejects weights that would reduce a score', function () {
    new ScoringWeights(allTimeLowMultiplier: 0.5);
})->throws(InvalidArgumentException::class);

it('exposes every rule it was built with', function () {
    expect(engine()->ruleIdentifiers())->toBe([
        'discount_threshold', 'below_historical_median', 'rating', 'review_volume',
        'prime', 'free_shipping', 'marketplace_trust', 'all_time_low',
    ]);
});
