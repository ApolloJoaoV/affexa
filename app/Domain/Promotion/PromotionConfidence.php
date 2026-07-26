<?php

declare(strict_types=1);

namespace App\Domain\Promotion;

/**
 * How much the price history behind a promotion can be trusted.
 *
 * Derived from sample count and history depth, never from the marketplace's own
 * list price. A low confidence promotion is never published automatically,
 * regardless of how high it scores.
 */
enum PromotionConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function allowsAutomaticPublication(): bool
    {
        return $this !== self::Low;
    }
}
