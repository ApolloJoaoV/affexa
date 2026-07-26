<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring;

use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceHistoryAggregates;
use App\Domain\Promotion\PromotionConfidence;

/**
 * Everything the engine needs to judge one product, gathered up front.
 *
 * The engine never queries. Handing it a fully populated context is what makes it
 * a pure function of its inputs — testable without a database, and safe to re-run
 * over thousands of past promotions when the weights are retuned.
 */
final readonly class PromotionContext
{
    public function __construct(
        public int $productId,
        public Money $currentPrice,
        public ?Money $previousPrice,
        public int $discountPercent,
        public PriceHistoryAggregates $history,
        public int $marketplaceTrustScore = 50,
        public ?float $rating = null,
        public int $reviewsCount = 0,
        public bool $isPrime = false,
        public bool $hasFreeShipping = false,
        public bool $inStock = true,
        public ?Money $lowestPriceEver = null,
    ) {}

    /**
     * Rebuilds a context from a persisted score breakdown.
     *
     * This is what makes the weight simulator possible: the observed value each
     * rule recorded is exactly that rule's input, so a past evaluation can be
     * replayed through the real rules under different weights — no marketplace is
     * called and no history is re-read.
     *
     * The reconstruction is synthetic where only a ratio was recorded. The median
     * rule stored how far below the median the price sat, not the two amounts, so a
     * price and median reproducing that same ratio are fabricated. The rule sees an
     * identical input and reaches an identical conclusion.
     *
     * @param  array<string, mixed>  $breakdown  the "rules" map of a stored breakdown
     */
    public static function fromRecordedObservations(
        array $breakdown,
        PromotionConfidence $confidence,
        int $productId = 0,
    ): self {
        $observed = static function (string $rule) use ($breakdown): mixed {
            $value = $breakdown[$rule]['observed'] ?? null;

            return $value;
        };

        $percentBelowMedian = is_numeric($observed('below_historical_median'))
            ? (float) $observed('below_historical_median')
            : null;

        // A median of exactly 100 makes the arithmetic transparent: a price of 60
        // is 40% below it.
        $median = Money::fromNumericString('100.00');
        $currentPrice = $percentBelowMedian === null
            ? $median
            : Money::fromCents((int) round($median->cents * (1 - $percentBelowMedian / 100)));

        $isAllTimeLow = $observed('all_time_low') === true;

        return new self(
            productId: $productId,
            currentPrice: $currentPrice,
            previousPrice: $median,
            discountPercent: is_numeric($observed('discount_threshold')) ? (int) $observed('discount_threshold') : 0,
            history: PriceHistoryAggregates::replayed(
                median: $percentBelowMedian === null ? null : $median,
                confidence: $confidence,
            ),
            marketplaceTrustScore: is_numeric($observed('marketplace_trust')) ? (int) $observed('marketplace_trust') : 0,
            rating: is_numeric($observed('rating')) ? (float) $observed('rating') : null,
            reviewsCount: is_numeric($observed('review_volume')) ? (int) $observed('review_volume') : 0,
            isPrime: $observed('prime') === true,
            hasFreeShipping: $observed('free_shipping') === true,
            inStock: true,
            lowestPriceEver: $isAllTimeLow ? $currentPrice : null,
        );
    }

    public function confidence(): PromotionConfidence
    {
        return $this->history->confidence();
    }

    /**
     * Whether the current price is at or below the cheapest we have ever recorded.
     */
    public function isAllTimeLow(): bool
    {
        if ($this->lowestPriceEver === null) {
            return false;
        }

        return ! $this->currentPrice->isGreaterThan($this->lowestPriceEver);
    }

    /**
     * How far below the 90 day median the current price sits, as a percentage.
     *
     * The median rather than the mean, because a handful of inflated readings drag
     * a mean upward and would manufacture a discount that never existed.
     */
    public function percentBelowMedian(): ?float
    {
        $median = $this->history->median90Days;

        if ($median === null || $median->isZero()) {
            return null;
        }

        return round(($median->cents - $this->currentPrice->cents) / $median->cents * 100, 2);
    }
}
