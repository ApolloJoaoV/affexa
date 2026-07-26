<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Seeds the scoring weights with the defaults from the specification.
 *
 * The defaults are duplicated here rather than read from ScoringWeights, because a
 * migration records what the values were at this point in time. Changing the class
 * defaults later must not silently rewrite history.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('scoring.discount_threshold', 20);
        $this->migrator->add('scoring.below_historical_median', 40);
        $this->migrator->add('scoring.rating', 15);
        $this->migrator->add('scoring.review_volume', 10);
        $this->migrator->add('scoring.prime', 10);
        $this->migrator->add('scoring.free_shipping', 10);
        $this->migrator->add('scoring.marketplace_trust', 10);
        $this->migrator->add('scoring.all_time_low_multiplier', 1.25);

        $this->migrator->add('scoring.minimum_discount_percent', 20);
        $this->migrator->add('scoring.minimum_rating', 4.5);
        $this->migrator->add('scoring.minimum_reviews', 500);

        /*
         * The automatic bar sits above the manual one: everything between the two
         * goes to a human, everything below is rejected. Settings validation must
         * refuse an inversion of these, which would silently publish what an
         * operator meant to review.
         */
        $this->migrator->add('scoring.minimum_score_for_automatic_publication', 75);
        $this->migrator->add('scoring.minimum_score_for_manual_review', 50);
    }

    public function down(): void
    {
        foreach ([
            'discount_threshold', 'below_historical_median', 'rating', 'review_volume',
            'prime', 'free_shipping', 'marketplace_trust', 'all_time_low_multiplier',
            'minimum_discount_percent', 'minimum_rating', 'minimum_reviews',
            'minimum_score_for_automatic_publication', 'minimum_score_for_manual_review',
        ] as $property) {
            $this->migrator->delete("scoring.{$property}");
        }
    }
};
