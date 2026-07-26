<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring\Rules;

use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\RuleResult;
use App\Domain\Promotion\Scoring\ScoringRule;
use App\Domain\Promotion\Scoring\ScoringWeights;

/**
 * Rewards a discount that clears the configured bar.
 *
 * Measured against our own previous price, not the marketplace's advertised
 * reference, which is routinely inflated.
 */
final class DiscountThresholdRule implements ScoringRule
{
    public function identifier(): string
    {
        return 'discount_threshold';
    }

    public function applies(PromotionContext $context): bool
    {
        return $context->previousPrice !== null;
    }

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult
    {
        $discount = $context->discountPercent;

        if ($discount < $weights->minimumDiscountPercent) {
            return RuleResult::denied(
                $this->identifier(),
                $this->maxPoints($weights),
                $discount,
                "Discount of {$discount}% is below the {$weights->minimumDiscountPercent}% bar.",
            );
        }

        return RuleResult::awarded(
            $this->identifier(),
            $this->maxPoints($weights),
            $this->maxPoints($weights),
            $discount,
            "Discount of {$discount}% clears the {$weights->minimumDiscountPercent}% bar.",
        );
    }

    public function maxPoints(ScoringWeights $weights): float
    {
        return (float) $weights->discountThreshold;
    }
}
