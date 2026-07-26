<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Marketplace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Marketplace>
 */
final class MarketplaceFactory extends Factory
{
    protected $model = Marketplace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'connector' => 'App\\Infrastructure\\Marketplace\\Connectors\\FakeConnector',
            'is_active' => true,
            'trust_score' => fake()->numberBetween(40, 100),
            'fetch_interval_minutes' => 60,
            'rate_limit_per_minute' => 60,
            'credentials' => null,
            'settings' => [],
            'consecutive_failures' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A marketplace the circuit breaker has taken out of rotation.
     */
    public function circuitOpen(): static
    {
        return $this->state(fn (): array => [
            'consecutive_failures' => 5,
            'circuit_open_until' => now()->addMinutes(30),
            'last_error_at' => now(),
            'last_error_message' => 'HTTP 503 from upstream',
        ]);
    }

    public function trusted(): static
    {
        return $this->state(fn (): array => ['trust_score' => 100]);
    }
}
