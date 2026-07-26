<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Casts;

use App\Domain\Pricing\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Bridges money_brl columns and the Money value object.
 *
 * Numeric values are read as reais, matching how they are written in the column
 * and in marketplace payloads. To build from cents, pass Money::fromCents()
 * explicitly — there is no ambiguity about which unit an integer means here.
 *
 * @implements CastsAttributes<Money|null, Money|string|int|float|null>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromNumericString((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->toNumericString(),
            is_int($value) => Money::fromNumericString((string) $value)->toNumericString(),
            is_float($value) => Money::fromFloat($value)->toNumericString(),
            is_string($value) => Money::fromNumericString($value)->toNumericString(),
            default => throw new InvalidArgumentException(
                "Cannot store [{$key}] from a ".get_debug_type($value).'; expected Money or a numeric value.'
            ),
        };
    }
}
