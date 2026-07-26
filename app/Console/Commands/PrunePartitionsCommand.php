<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Database\PartitionedTable;
use App\Infrastructure\Database\PartitionManager;
use App\Infrastructure\Database\PriceHistoryConsolidator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retires partitions that have fallen outside the retention window.
 *
 * Price history is consolidated into monthly aggregates first, so statistics
 * survive the loss of the detailed rows.
 */
final class PrunePartitionsCommand extends Command
{
    protected $signature = 'promohub:partitions:prune
                            {--dry-run : Report what would be retired without touching anything}
                            {--drop : Drop detached partitions instead of leaving them in place}';

    protected $description = 'Consolidate and retire partitions past their retention window';

    public function handle(PartitionManager $partitions, PriceHistoryConsolidator $consolidator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $drop = (bool) $this->option('drop') || (bool) config('promohub.retention.drop_after_detach');

        $retired = 0;

        foreach ($this->cutoffs() as $tableName => $cutoff) {
            $table = PartitionedTable::from($tableName);
            $expired = $partitions->partitionsOlderThan($table, $cutoff);

            if ($expired === []) {
                $this->components->info("{$table->value}: nothing older than {$cutoff->toDateString()}");

                continue;
            }

            foreach ($expired as $partition) {
                $name = $partition['name'];

                if ($dryRun) {
                    $this->components->twoColumnDetail($name, 'would be retired');

                    continue;
                }

                if ($table === PartitionedTable::PriceHistory) {
                    $rows = $consolidator->consolidate($name);
                    $this->components->twoColumnDetail($name, "consolidated {$rows} aggregate rows");
                }

                $partitions->detach($table, $name, $drop);
                $this->components->twoColumnDetail($name, $drop ? 'detached and dropped' : 'detached');
                $retired++;
            }
        }

        if ($dryRun) {
            $this->components->warn('Dry run: nothing was changed.');
        }

        $this->components->info("Retired {$retired} partition(s).");

        return self::SUCCESS;
    }

    /**
     * The oldest moment each table must still retain.
     *
     * @return array<string, CarbonImmutable>
     */
    private function cutoffs(): array
    {
        $now = CarbonImmutable::now();

        return [
            PartitionedTable::PriceHistory->value => $now
                ->startOfMonth()
                ->subMonths((int) config('promohub.retention.price_history_months', 12)),
            PartitionedTable::ApiCallLogs->value => $now
                ->startOfDay()
                ->subDays((int) config('promohub.retention.api_call_logs_days', 21)),
        ];
    }
}
