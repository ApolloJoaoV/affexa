<?php

declare(strict_types=1);

namespace App\Domain\Promotion;

/**
 * Why a promotion was not published. Recorded so that tuning the scoring weights
 * is driven by data rather than guesswork.
 */
enum RejectionReason: string
{
    case BelowMinimumDiscount = 'below_minimum_discount';
    case BelowMinimumScore = 'below_minimum_score';
    case NotBelowHistoricalMedian = 'not_below_historical_median';
    case InsufficientHistory = 'insufficient_history';
    case DuplicateWithinWindow = 'duplicate_within_window';
    case CategoryInactive = 'category_inactive';
    case OutOfStock = 'out_of_stock';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
