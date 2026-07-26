<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Lifecycle of a single send attempt on a single channel.
 *
 * Kept separate from the promotion's own status so the same promotion can be
 * published to WhatsApp today and Telegram tomorrow without re-evaluating it.
 */
enum PublicationStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Cancelled], true);
    }
}
