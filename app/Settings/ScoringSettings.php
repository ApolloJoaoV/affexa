<?php

declare(strict_types=1);

namespace App\Settings;

use App\Domain\Promotion\Scoring\ScoringWeights;
use Spatie\LaravelSettings\Settings;

/**
 * The scoring weights, editable from the panel.
 *
 * Kept out of App\Domain on purpose: the engine takes a plain ScoringWeights value
 * object, so it stays free of the framework and a simulation can score against
 * hypothetical weights that were never saved.
 */
final class ScoringSettings extends Settings
{
    public int $discount_threshold;

    public int $below_historical_median;

    public int $rating;

    public int $review_volume;

    public int $prime;

    public int $free_shipping;

    public int $marketplace_trust;

    public float $all_time_low_multiplier;

    public int $minimum_discount_percent;

    public float $minimum_rating;

    public int $minimum_reviews;

    /**
     * Score at or above which a promotion publishes without a human.
     */
    public int $minimum_score_for_automatic_publication;

    /**
     * Score at or above which a promotion is queued for manual approval. Anything
     * below is rejected outright.
     */
    public int $minimum_score_for_manual_review;

    public static function group(): string
    {
        return 'scoring';
    }

    public function toWeights(): ScoringWeights
    {
        return ScoringWeights::fromArray([
            'discount_threshold' => $this->discount_threshold,
            'below_historical_median' => $this->below_historical_median,
            'rating' => $this->rating,
            'review_volume' => $this->review_volume,
            'prime' => $this->prime,
            'free_shipping' => $this->free_shipping,
            'marketplace_trust' => $this->marketplace_trust,
            'all_time_low_multiplier' => $this->all_time_low_multiplier,
            'minimum_discount_percent' => $this->minimum_discount_percent,
            'minimum_rating' => $this->minimum_rating,
            'minimum_reviews' => $this->minimum_reviews,
        ]);
    }
}
