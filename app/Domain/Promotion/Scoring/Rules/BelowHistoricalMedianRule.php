<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring\Rules;

use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\RuleResult;
use App\Domain\Promotion\Scoring\ScoringRule;
use App\Domain\Promotion\Scoring\ScoringWeights;

/**
 * The heaviest signal: is this actually cheaper than the product normally is?
 *
 * Compared against our own 90 day median. A marketplace can claim any reference
 * price it likes; it cannot fake the prices we recorded ourselves.
 */
final class BelowHistoricalMedianRule implements ScoringRule
{
    public function identifier(): string
    {
        return 'below_historical_median';
    }

    public function applies(PromotionContext $context): bool
    {
        return $context->history->median90Days !== null;
    }

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult
    {
        if (! $this->applies($context)) {
            return RuleResult::notApplicable(
                $this->identifier(),
                $this->maxPoints($weights),
                'No 90 day median available; the price cannot be judged against history.',
            );
        }

        $percentBelow = $context->percentBelowMedian() ?? 0.0;

        if ($percentBelow <= 0) {
            return RuleResult::denied(
                $this->identifier(),
                $this->maxPoints($weights),
                $percentBelow,
                'Price is at or above the historical median.',
            );
        }

        return RuleResult::awarded(
            $this->identifier(),
            $this->maxPoints($weights),
            $this->maxPoints($weights),
            $percentBelow,
            "Price is {$percentBelow}% below the 90 day median.",
        );
    }

    public function maxPoints(ScoringWeights $weights): float
    {
        return (float) $weights->belowHistoricalMedian;
    }
}
