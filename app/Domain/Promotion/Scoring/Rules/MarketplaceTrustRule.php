<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring\Rules;

use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\RuleResult;
use App\Domain\Promotion\Scoring\ScoringRule;
use App\Domain\Promotion\Scoring\ScoringWeights;

/**
 * Proportional rather than pass/fail: trust is a spectrum, and a marketplace at 80
 * should not score the same as one at 20.
 */
final class MarketplaceTrustRule implements ScoringRule
{
    public function identifier(): string
    {
        return 'marketplace_trust';
    }

    public function applies(PromotionContext $context): bool
    {
        return true;
    }

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult
    {
        $trust = max(0, min(100, $context->marketplaceTrustScore));
        $points = $this->maxPoints($weights) * ($trust / 100);

        return RuleResult::awarded(
            $this->identifier(),
            $points,
            $this->maxPoints($weights),
            $trust,
            "Marketplace trust score of {$trust}.",
        );
    }

    public function maxPoints(ScoringWeights $weights): float
    {
        return (float) $weights->marketplaceTrust;
    }
}
