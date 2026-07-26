<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Where a price observation came from. Kept on every history row so that a
 * manually corrected price can be told apart from an API reading when auditing a
 * suspicious discount.
 */
enum PriceSource: string
{
    case Api = 'api';
    case Scrape = 'scrape';
    case Manual = 'manual';
    case Backfill = 'backfill';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
