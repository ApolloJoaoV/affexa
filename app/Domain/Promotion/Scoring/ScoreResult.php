<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring;

/**
 * The engine's verdict: a score from 0 to 100 and the full reasoning behind it.
 */
final readonly class ScoreResult
{
    /**
     * @param  array<string, array<string, mixed>>  $breakdown  keyed by rule identifier
     */
    public function __construct(
        public int $score,
        public array $breakdown,
        public float $rawPoints,
        public float $totalPossible,
        public float $multiplier = 1.0,
    ) {}

    public function wasMultiplied(): bool
    {
        return $this->multiplier > 1.0;
    }

    /**
     * Points earned by one rule, for assertions and for the simulation screen.
     */
    public function pointsFor(string $rule): ?float
    {
        $points = $this->breakdown[$rule]['points'] ?? null;

        return is_numeric($points) ? (float) $points : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'raw_points' => round($this->rawPoints, 2),
            'total_possible' => round($this->totalPossible, 2),
            'multiplier' => $this->multiplier,
            'rules' => $this->breakdown,
        ];
    }
}
