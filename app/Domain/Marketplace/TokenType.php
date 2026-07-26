<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

enum TokenType: string
{
    case Access = 'access';
    case Refresh = 'refresh';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
