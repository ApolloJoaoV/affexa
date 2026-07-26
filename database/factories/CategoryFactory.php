<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = implode(' ', (array) fake()->unique()->words(2));
        $slug = Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999);

        return [
            'parent_id' => null,
            'name' => Str::title($name),
            'slug' => $slug,
            'path' => $slug,
            'is_active' => true,
            'min_score_override' => null,
            'min_discount_override' => null,
        ];
    }

    /**
     * Nests this category under another, maintaining the materialised path.
     */
    public function childOf(Category $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_id' => $parent->id,
            'path' => $parent->path.'/'.$attributes['slug'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A category that demands a higher bar than the global setting.
     */
    public function strict(int $minScore = 70, int $minDiscount = 30): static
    {
        return $this->state(fn (): array => [
            'min_score_override' => $minScore,
            'min_discount_override' => $minDiscount,
        ]);
    }
}
