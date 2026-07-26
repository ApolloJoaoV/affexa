<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring;

/**
 * One scoring signal.
 *
 * Rules are separate classes rather than branches in one method so that a new
 * signal is a new file, and so each can be unit tested on its own. None of them
 * may query anything: everything they need is already in the context.
 */
interface ScoringRule
{
    /**
     * Stable key, used in the persisted breakdown. Changing it orphans historical
     * breakdowns, so treat it as part of the data contract.
     */
    public function identifier(): string;

    /**
     * Whether this rule can be evaluated at all for the given context.
     */
    public function applies(PromotionContext $context): bool;

    public function score(PromotionContext $context, ScoringWeights $weights): RuleResult;

    /**
     * The most this rule can contribute, used for normalisation.
     */
    public function maxPoints(ScoringWeights $weights): float;
}
