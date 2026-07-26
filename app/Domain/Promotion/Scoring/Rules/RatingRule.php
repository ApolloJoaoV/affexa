<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring\Rules;

use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\RuleResult;
use App\Domain\Promotion\Scoring\ScoringRule;
use App\Domain\Promotion\Scoring\ScoringWeights;

final class RatingRule implements ScoringRule
{
    public function identifier(): string
    {
        return 'rating';
    }

    public function applies(PromotionContext $context): bool
    {
        return $context->rating !== null;
    }

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult
    {
        if ($context->rating === null) {
            return RuleResult::notApplicable(
                $this->identifier(),
                $this->maxPoints($weights),
                'The marketplace supplied no rating.',
            );
        }

        if ($context->rating < $weights->minimumRating) {
            return RuleResult::denied(
                $this->identifier(),
                $this->maxPoints($weights),
                $context->rating,
                "Rating {$context->rating} is below {$weights->minimumRating}.",
            );
        }

        return RuleResult::awarded(
            $this->identifier(),
            $this->maxPoints($weights),
            $this->maxPoints($weights),
            $context->rating,
            "Rating {$context->rating} meets the bar.",
        );
    }

    public function maxPoints(ScoringWeights $weights): float
    {
        return (float) $weights->rating;
    }
}
