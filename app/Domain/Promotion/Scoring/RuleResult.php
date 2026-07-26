<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Scoring;

/**
 * What one rule concluded, and why.
 *
 * The observed value is as important as the points: without it, retuning the
 * weights later means guessing at what the engine actually saw.
 */
final readonly class RuleResult
{
    private function __construct(
        public string $rule,
        public float $points,
        public float $maxPoints,
        public mixed $observed,
        public string $reason,
        public ?float $multiplier = null,
    ) {}

    public static function awarded(string $rule, float $points, float $maxPoints, mixed $observed, string $reason): self
    {
        return new self($rule, $points, $maxPoints, $observed, $reason);
    }

    public static function denied(string $rule, float $maxPoints, mixed $observed, string $reason): self
    {
        return new self($rule, 0.0, $maxPoints, $observed, $reason);
    }

    /**
     * A rule that could not be evaluated at all, e.g. no history to compare
     * against. Distinct from a denial: nothing was measured.
     */
    public static function notApplicable(string $rule, float $maxPoints, string $reason): self
    {
        return new self($rule, 0.0, $maxPoints, null, $reason);
    }

    public static function multiplied(string $rule, float $multiplier, mixed $observed, string $reason): self
    {
        return new self($rule, 0.0, 0.0, $observed, $reason, $multiplier);
    }

    /**
     * @return array{points: float, max: float, observed: mixed, reason: string, multiplier?: float}
     */
    public function toArray(): array
    {
        $payload = [
            'points' => round($this->points, 2),
            'max' => round($this->maxPoints, 2),
            'observed' => $this->observed,
            'reason' => $this->reason,
        ];

        if ($this->multiplier !== null) {
            $payload['multiplier'] = $this->multiplier;
        }

        return $payload;
    }
}
