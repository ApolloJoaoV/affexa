<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring\Rules;

use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\RuleResult;
use App\Domain\Promotion\Scoring\ScoringRule;
use App\Domain\Promotion\Scoring\ScoringWeights;

/**
 * A high rating from four buyers means little; the same rating from thousands is
 * evidence. This rule scores the sample size behind the rating.
 */
final class ReviewVolumeRule implements ScoringRule
{
    public function identifier(): string
    {
        return 'review_volume';
    }

    public function applies(PromotionContext $context): bool
    {
        return true;
    }

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult
    {
        if ($context->reviewsCount < $weights->minimumReviews) {
            return RuleResult::denied(
                $this->identifier(),
                $this->maxPoints($weights),
                $context->reviewsCount,
                "{$context->reviewsCount} reviews is below {$weights->minimumReviews}.",
            );
        }

        return RuleResult::awarded(
            $this->identifier(),
            $this->maxPoints($weights),
            $this->maxPoints($weights),
            $context->reviewsCount,
            "{$context->reviewsCount} reviews clear the bar.",
        );
    }

    public function maxPoints(ScoringWeights $weights): float
    {
        return (float) $weights->reviewVolume;
    }
}
