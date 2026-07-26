<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pricing\Money;
use App\Domain\Promotion\PromotionConfidence;
use App\Domain\Promotion\PromotionStatus;
use App\Domain\Promotion\RejectionReason;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
final class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->numberBetween(1000, 200000) / 100;
        $previous = $price * 1.5;

        return [
            'product_id' => Product::factory(),
            'marketplace_id' => fn (array $attributes): int => Product::query()
                ->whereKey($attributes['product_id'])
                ->firstOrFail()
                ->marketplace_id,
            'price' => Money::fromFloat($price),
            'previous_price' => Money::fromFloat($previous),
            'discount_percent' => 33,
            'score' => fake()->numberBetween(50, 100),
            'score_breakdown' => [
                'discount_threshold' => ['points' => 20, 'observed' => 33],
                'below_historical_median' => ['points' => 40, 'observed' => true],
            ],
            'confidence' => PromotionConfidence::High,
            'status' => PromotionStatus::Pending,
            'rejection_reason' => null,
            'evaluated_at' => now(),
            'dedupe_hash' => '\x'.hash('sha256', Str::random(32)),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => PromotionStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PromotionStatus::Published,
            'approved_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function rejected(RejectionReason $reason = RejectionReason::BelowMinimumScore): static
    {
        return $this->state(fn (): array => [
            'status' => PromotionStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Thin history, so the promotion may never publish automatically however well
     * it scores.
     */
    public function lowConfidence(): static
    {
        return $this->state(fn (): array => ['confidence' => PromotionConfidence::Low]);
    }

    public function scored(int $score): static
    {
        return $this->state(fn (): array => ['score' => $score]);
    }
}
