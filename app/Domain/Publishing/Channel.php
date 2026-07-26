<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Publication channels the architecture knows about.
 *
 * Every channel is present from the start even though only WhatsApp sends. The
 * disabled ones exist as registered classes that throw, which keeps the
 * extension path exercised: turning one on means implementing a send method, not
 * changing the pipeline.
 */
enum Channel: string
{
    case WhatsApp = 'whatsapp';
    case Telegram = 'telegram';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case X = 'x';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Telegram => 'Telegram',
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::X => 'X',
        };
    }
}
