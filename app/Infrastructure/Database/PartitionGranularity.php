<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Carbon\CarbonImmutable;

enum PartitionGranularity: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';

    /**
     * Start of the period containing the given moment, in the application time
     * zone. Boundaries are expressed in local time so that a month partition
     * begins at local midnight rather than 21:00 the previous day.
     */
    public function startOf(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $moment->startOfMonth(),
            self::Weekly => $moment->startOfWeek(CarbonImmutable::MONDAY),
        };
    }

    public function next(CarbonImmutable $periodStart): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $periodStart->addMonth(),
            self::Weekly => $periodStart->addWeek(),
        };
    }

    /**
     * Partition name suffix, e.g. "2026_08" for a month or "2026_w31" for a week.
     */
    public function suffixFor(CarbonImmutable $periodStart): string
    {
        return match ($this) {
            self::Monthly => $periodStart->format('Y_m'),
            self::Weekly => sprintf('%s_w%02d', $periodStart->isoFormat('GGGG'), (int) $periodStart->isoFormat('WW')),
        };
    }
}
