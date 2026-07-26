<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring;

use InvalidArgumentException;

/**
 * The tunable half of the scoring engine.
 *
 * A plain value object rather than the settings class itself, so the engine stays
 * free of the framework and a test can score against arbitrary weights without
 * touching the database.
 */
final readonly class ScoringWeights
{
    public function __construct(
        public int $discountThreshold = 20,
        public int $belowHistoricalMedian = 40,
        public int $rating = 15,
        public int $reviewVolume = 10,
        public int $prime = 10,
        public int $freeShipping = 10,
        public int $marketplaceTrust = 10,
        /**
         * Applied as a multiplier rather than points: an all time low is the
         * single strongest evidence that a discount is real, and adding a fixed
         * amount would let a mediocre deal reach the same score by accumulating
         * weak signals.
         */
        public float $allTimeLowMultiplier = 1.25,
        public int $minimumDiscountPercent = 20,
        public float $minimumRating = 4.5,
        public int $minimumReviews = 500,
    ) {
        foreach ([
            'discountThreshold' => $discountThreshold,
            'belowHistoricalMedian' => $belowHistoricalMedian,
            'rating' => $rating,
            'reviewVolume' => $reviewVolume,
            'prime' => $prime,
            'freeShipping' => $freeShipping,
            'marketplaceTrust' => $marketplaceTrust,
        ] as $name => $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException("Weight [{$name}] cannot be negative.");
            }
        }

        if ($allTimeLowMultiplier < 1.0) {
            throw new InvalidArgumentException('The all time low multiplier cannot reduce a score.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = new self;

        return new self(
            discountThreshold: (int) ($values['discount_threshold'] ?? $defaults->discountThreshold),
            belowHistoricalMedian: (int) ($values['below_historical_median'] ?? $defaults->belowHistoricalMedian),
            rating: (int) ($values['rating'] ?? $defaults->rating),
            reviewVolume: (int) ($values['review_volume'] ?? $defaults->reviewVolume),
            prime: (int) ($values['prime'] ?? $defaults->prime),
            freeShipping: (int) ($values['free_shipping'] ?? $defaults->freeShipping),
            marketplaceTrust: (int) ($values['marketplace_trust'] ?? $defaults->marketplaceTrust),
            allTimeLowMultiplier: (float) ($values['all_time_low_multiplier'] ?? $defaults->allTimeLowMultiplier),
            minimumDiscountPercent: (int) ($values['minimum_discount_percent'] ?? $defaults->minimumDiscountPercent),
            minimumRating: (float) ($values['minimum_rating'] ?? $defaults->minimumRating),
            minimumReviews: (int) ($values['minimum_reviews'] ?? $defaults->minimumReviews),
        );
    }

    /**
     * The denominator used for normalisation.
     *
     * Deliberately the total across every rule, not only the ones that could be
     * evaluated. A product with no price history cannot earn the historical median
     * points, and it should not reach 100 regardless — a missing signal is missing
     * evidence, and losing those points is the intended cost.
     */
    public function totalPossible(): int
    {
        return $this->discountThreshold
            + $this->belowHistoricalMedian
            + $this->rating
            + $this->reviewVolume
            + $this->prime
            + $this->freeShipping
            + $this->marketplaceTrust;
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'discount_threshold' => $this->discountThreshold,
            'below_historical_median' => $this->belowHistoricalMedian,
            'rating' => $this->rating,
            'review_volume' => $this->reviewVolume,
            'prime' => $this->prime,
            'free_shipping' => $this->freeShipping,
            'marketplace_trust' => $this->marketplaceTrust,
            'all_time_low_multiplier' => $this->allTimeLowMultiplier,
            'minimum_discount_percent' => $this->minimumDiscountPercent,
            'minimum_rating' => $this->minimumRating,
            'minimum_reviews' => $this->minimumReviews,
        ];
    }
}
