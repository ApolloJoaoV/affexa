<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Marketplace\TokenType;
use App\Models\Marketplace;
use App\Models\MarketplaceToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketplaceToken>
 */
final class MarketplaceTokenFactory extends Factory
{
    protected $model = MarketplaceToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'marketplace_id' => Marketplace::factory(),
            'type' => TokenType::Access,
            'value' => Str::random(64),
            'expires_at' => now()->addHour(),
            'rotated_at' => null,
            'metadata' => [],
        ];
    }

    public function refresh(): static
    {
        return $this->state(fn (): array => [
            'type' => TokenType::Refresh,
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    /**
     * Superseded by a newer token, kept for audit.
     */
    public function rotated(): static
    {
        return $this->state(fn (): array => ['rotated_at' => now()]);
    }
}
