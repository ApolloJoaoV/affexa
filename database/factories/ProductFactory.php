<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pricing\Money;
use App\Models\Category;
use App\Models\Marketplace;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = implode(' ', (array) fake()->words(4));
        $externalId = (string) fake()->unique()->numberBetween(1, 999999999);
        $current = fake()->numberBetween(1000, 500000) / 100;

        return [
            'marketplace_id' => Marketplace::factory(),
            'external_id' => $externalId,
            // Hex input syntax for bytea, so the value binds as an ordinary
            // parameter instead of being interpolated into raw SQL.
            'identity_hash' => '\x'.hash('sha256', $externalId),
            'title' => ucfirst($title),
            'normalized_title' => $this->normalize($title),
            'brand' => fake()->company(),
            'category_id' => null,
            'image_url' => fake()->imageUrl(),
            'product_url' => fake()->url(),
            'affiliate_url' => null,
            'rating' => fake()->randomFloat(1, 3, 5),
            'reviews_count' => fake()->numberBetween(0, 5000),
            'is_prime' => fake()->boolean(30),
            'has_free_shipping' => fake()->boolean(60),
            'in_stock' => true,
            'current_price' => Money::fromFloat($current),
            'previous_price' => Money::fromFloat($current * 1.4),
            'lowest_price_ever' => Money::fromFloat($current),
            'highest_price_ever' => Money::fromFloat($current * 1.4),
            'raw_payload' => ['source' => 'factory'],
        ];
    }

    /**
     * Sets both prices explicitly, so a test can assert on the generated
     * discount_percent without guessing.
     */
    public function pricedAt(string $current, ?string $previous = null): static
    {
        return $this->state(fn (): array => [
            'current_price' => Money::fromNumericString($current),
            'previous_price' => $previous === null ? null : Money::fromNumericString($previous),
        ]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn (): array => ['category_id' => $category->id]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['in_stock' => false]);
    }

    public function titled(string $title): static
    {
        return $this->state(fn (): array => [
            'title' => $title,
            'normalized_title' => $this->normalize($title),
        ]);
    }

    /**
     * Lowercased, unaccented, unpunctuated, matching what the pipeline stores.
     */
    private function normalize(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $title);

        return trim(preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($ascii === false ? $title : $ascii)) ?? '');
    }
}
