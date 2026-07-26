<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring\Rules;

use App\Domain\Promotion\Scoring\PromotionContext;
use App\Domain\Promotion\Scoring\RuleResult;
use App\Domain\Promotion\Scoring\ScoringRule;
use App\Domain\Promotion\Scoring\ScoringWeights;

final class FreeShippingRule implements ScoringRule
{
    public function identifier(): string
    {
        return 'free_shipping';
    }

    public function applies(PromotionContext $context): bool
    {
        return true;
    }

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult
    {
        return $context->hasFreeShipping
            ? RuleResult::awarded($this->identifier(), $this->maxPoints($weights), $this->maxPoints($weights), true, 'Ships free.')
            : RuleResult::denied($this->identifier(), $this->maxPoints($weights), false, 'Shipping is charged.');
    }

    public function maxPoints(ScoringWeights $weights): float
    {
        return (float) $weights->freeShipping;
    }
}
