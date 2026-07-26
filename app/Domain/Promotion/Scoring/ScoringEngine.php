<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring;

/**
 * Composes the rules into a single score.
 *
 * Pure by construction: it holds no dependencies beyond the rules, issues no
 * queries and reads no configuration. That is what allows the simulation screen to
 * re-score five hundred past promotions against new weights without touching a
 * single marketplace.
 */
final class ScoringEngine
{
    /**
     * @var list<ScoringRule>
     */
    private array $rules;

    /**
     * @param  iterable<ScoringRule>  $rules
     */
    public function __construct(iterable $rules)
    {
        $this->rules = is_array($rules) ? array_values($rules) : iterator_to_array($rules, false);
    }

    public function score(PromotionContext $context, ScoringWeights $weights): ScoreResult
    {
        $earned = 0.0;
        $possible = 0.0;
        $multiplier = 1.0;
        $breakdown = [];

        foreach ($this->rules as $rule) {
            $result = $rule->applies($context)
                ? $rule->score($context, $weights)
                : RuleResult::notApplicable($rule->identifier(), $rule->maxPoints($weights), 'Rule does not apply.');

            $earned += $result->points;

            /*
             * The denominator counts every rule's ceiling, including the ones that
             * could not be evaluated. A product with no price history therefore
             * cannot reach 100 — the missing evidence costs it those points, which
             * is the intended behaviour rather than an oversight.
             */
            $possible += $rule->maxPoints($weights);

            if ($result->multiplier !== null) {
                $multiplier *= $result->multiplier;
            }

            $breakdown[$rule->identifier()] = $result->toArray();
        }

        $normalised = $possible > 0.0 ? ($earned / $possible) * 100 : 0.0;
        $score = (int) min(100, max(0, (int) round($normalised * $multiplier)));

        return new ScoreResult(
            score: $score,
            breakdown: $breakdown,
            rawPoints: $earned,
            totalPossible: $possible,
            multiplier: $multiplier,
        );
    }

    /**
     * @return list<string>
     */
    public function ruleIdentifiers(): array
    {
        return array_map(static fn (ScoringRule $rule): string => $rule->identifier(), $this->rules);
    }
}
