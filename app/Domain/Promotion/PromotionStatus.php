<?php

declare(strict_types=1);

namespace App\Domain\Promotion;

/**
 * Lifecycle of an evaluated promotion.
 *
 * Backed by varchar plus a CHECK constraint in the database rather than a native
 * PostgreSQL enum: adding a value to a native enum requires ALTER TYPE, which
 * cannot run in a transaction alongside other DDL and complicates rollback. A
 * CHECK constraint is replaceable in an ordinary migration.
 */
enum PromotionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Published = 'published';
    case Expired = 'expired';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Statuses that still occupy a deduplication slot. A product already pending
     * or published must not produce a second promotion for the same deal.
     *
     * @return list<self>
     */
    public static function occupyingDedupeSlot(): array
    {
        return [self::Pending, self::Approved, self::Published];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Expired, self::Failed], true);
    }
}
