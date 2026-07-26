<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring\Rules;

use App\Domain\Promotion\PromotionConfidence;
use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\RuleResult;
use App\Domain\Promotion\Scoring\ScoringRule;
use App\Domain\Promotion\Scoring\ScoringWeights;

/**
 * The cheapest this product has ever been, on history deep enough to mean it.
 *
 * Contributes a multiplier rather than points, and contributes nothing to the
 * normalisation denominator. Fixed points would let a mediocre deal reach the same
 * score by stacking weak signals; a multiplier can only lift something that
 * already scored well on the evidence.
 *
 * High confidence is required: "lowest ever" across four samples from last week is
 * not a claim worth amplifying.
 */
final class AllTimeLowRule implements ScoringRule
{
    public function identifier(): string
    {
        return 'all_time_low';
    }

    public function applies(PromotionContext $context): bool
    {
        return $context->lowestPriceEver !== null;
    }

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult
    {
        if (! $this->applies($context)) {
            return RuleResult::notApplicable($this->identifier(), 0.0, 'No recorded all time low.');
        }

        if (! $context->isAllTimeLow()) {
            return RuleResult::denied($this->identifier(), 0.0, false, 'Not the cheapest price on record.');
        }

        if ($context->confidence() !== PromotionConfidence::High) {
            return RuleResult::denied(
                $this->identifier(),
                0.0,
                true,
                'All time low, but the history is too thin to amplify it.',
            );
        }

        return RuleResult::multiplied(
            $this->identifier(),
            $weights->allTimeLowMultiplier,
            true,
            'Cheapest price ever recorded, on deep history.',
        );
    }

    /**
     * Zero on purpose: this rule multiplies, so counting it in the denominator
     * would make a perfect score unreachable.
     */
    public function maxPoints(ScoringWeights $weights): float
    {
        return 0.0;
    }
}
