<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Database\PartitionedTable;
use App\Infrastructure\Database\PartitionManager;
use Illuminate\Console\Command;

/**
 * Provisions future partitions.
 *
 * Runs daily. A missing partition is not a slow query, it is a failed insert for
 * every row in that period, so this keeps several periods provisioned ahead
 * rather than creating them just in time.
 */
final class EnsurePartitionsCommand extends Command
{
    protected $signature = 'promohub:partitions:ensure
                            {--table= : Only this partitioned table}
                            {--periods= : Override how many future periods to provision}';

    protected $description = 'Create the partitions for the current and upcoming periods';

    public function handle(PartitionManager $partitions): int
    {
        $tables = $this->targetTables();

        if ($tables === []) {
            $this->components->error('Unknown table. Expected one of: '.implode(', ', array_column(PartitionedTable::cases(), 'value')));

            return self::FAILURE;
        }

        $periods = $this->option('periods');

        foreach ($tables as $table) {
            $created = $partitions->ensure(
                $table,
                periodsAhead: $periods === null ? null : (int) $periods,
            );

            if ($created === []) {
                $this->components->info("{$table->value}: already provisioned");

                continue;
            }

            foreach ($created as $partition) {
                $this->components->twoColumnDetail($table->value, "created {$partition}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<PartitionedTable>
     */
    private function targetTables(): array
    {
        $requested = $this->option('table');

        if ($requested === null) {
            return PartitionedTable::cases();
        }

        $table = PartitionedTable::tryFrom((string) $requested);

        return $table === null ? [] : [$table];
    }
}
