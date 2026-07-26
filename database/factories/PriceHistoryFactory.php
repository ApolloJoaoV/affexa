<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceSource;
use App\Models\PriceHistory;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceHistory>
 */
final class PriceHistoryFactory extends Factory
{
    protected $model = PriceHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'marketplace_id' => fn (array $attributes): int => Product::query()
                ->whereKey($attributes['product_id'])
                ->firstOrFail()
                ->marketplace_id,
            'price' => Money::fromFloat(fake()->numberBetween(1000, 500000) / 100),
            'list_price' => null,
            'in_stock' => true,
            'source' => PriceSource::Api,
            'collected_at' => CarbonImmutable::now(),
        ];
    }

    public function at(CarbonImmutable|string $moment): static
    {
        return $this->state(fn (): array => [
            'collected_at' => $moment instanceof CarbonImmutable ? $moment : CarbonImmutable::parse($moment),
        ]);
    }

    public function pricedAt(string $price): static
    {
        return $this->state(fn (): array => ['price' => Money::fromNumericString($price)]);
    }

    /**
     * An observation N days back, useful for seeding a history that spans
     * several monthly partitions.
     */
    public function daysAgo(int $days, ?string $price = null): static
    {
        return $this->state(fn (): array => array_filter([
            'collected_at' => CarbonImmutable::now()->subDays($days),
            'price' => $price === null ? null : Money::fromNumericString($price),
        ]));
    }
}
