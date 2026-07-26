<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates, inspects and retires range partitions.
 *
 * A missing partition is not a degraded state, it is a hard insert failure for
 * every row in that period, so provisioning runs well ahead of time and is
 * idempotent enough to run daily.
 */
final class PartitionManager
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * Ensures the partition covering $from exists, plus the configured number of
     * following periods.
     *
     * @return list<string> names of the partitions created by this call
     */
    public function ensure(PartitionedTable $table, ?CarbonImmutable $from = null, ?int $periodsAhead = null): array
    {
        $granularity = $table->granularity();
        $periodStart = $granularity->startOf($from ?? CarbonImmutable::now());
        $periods = ($periodsAhead ?? $table->periodsAhead()) + 1;

        $created = [];

        for ($index = 0; $index < $periods; $index++) {
            $name = $this->createPartition($table, $periodStart);

            if ($name !== null) {
                $created[] = $name;
            }

            $periodStart = $granularity->next($periodStart);
        }

        return $created;
    }

    /**
     * @return string|null the partition name if it was created, null if it existed
     */
    private function createPartition(PartitionedTable $table, CarbonImmutable $periodStart): ?string
    {
        $granularity = $table->granularity();
        $partition = $table->value.'_'.$granularity->suffixFor($periodStart);
        $periodEnd = $granularity->next($periodStart);

        if ($this->partitionExists($partition)) {
            return null;
        }

        /*
         * Bounds are inclusive of FROM and exclusive of TO, and are written with
         * an explicit offset so the boundary is unambiguous regardless of the
         * session time zone.
         */
        $this->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
            $this->quoteIdentifier($partition),
            $this->quoteIdentifier($table->value),
            $this->quoteTimestamp($periodStart),
            $this->quoteTimestamp($periodEnd),
        ));

        foreach ($table->indexesFor($partition) as $indexDdl) {
            $this->statement($indexDdl);
        }

        $parameters = $table->storageParameters();

        if ($parameters !== []) {
            $assignments = [];

            foreach ($parameters as $parameter => $value) {
                $assignments[] = $this->quoteIdentifier($parameter).' = '.$this->quoteLiteral($value);
            }

            $this->statement(sprintf(
                'ALTER TABLE %s SET (%s)',
                $this->quoteIdentifier($partition),
                implode(', ', $assignments),
            ));
        }

        return $partition;
    }

    /**
     * Existing partitions of a table, oldest first.
     *
     * @return list<array{name: string, from: CarbonImmutable|null, to: CarbonImmutable|null}>
     */
    public function partitions(PartitionedTable $table): array
    {
        /** @var list<object{name: string, bounds: string}> $rows */
        $rows = $this->connection()->select(<<<'SQL'
            SELECT child.relname AS name,
                   pg_get_expr(child.relpartbound, child.oid) AS bounds
            FROM pg_inherits
            JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
            JOIN pg_class child ON child.oid = pg_inherits.inhrelid
            WHERE parent.relname = ?
            ORDER BY child.relname
        SQL, [$table->value]);

        return array_map(
            fn (object $row): array => [
                'name' => $row->name,
                'from' => $this->extractBound($row->bounds, 1),
                'to' => $this->extractBound($row->bounds, 2),
            ],
            $rows
        );
    }

    /**
     * Partitions whose entire range sits before $before, i.e. those eligible for
     * retirement.
     *
     * @return list<array{name: string, from: CarbonImmutable|null, to: CarbonImmutable|null}>
     */
    public function partitionsOlderThan(PartitionedTable $table, CarbonImmutable $before): array
    {
        return array_values(array_filter(
            $this->partitions($table),
            fn (array $partition): bool => $partition['to'] !== null && $partition['to']->lessThanOrEqualTo($before)
        ));
    }

    /**
     * Detaches a partition from its parent, optionally dropping it afterwards.
     *
     * CONCURRENTLY avoids holding an ACCESS EXCLUSIVE lock on the parent, which
     * would block every insert in the pipeline for the duration. It cannot run
     * inside a transaction block, hence the explicit statement here and the
     * withinTransaction guard in the calling command.
     */
    public function detach(PartitionedTable $table, string $partition, bool $drop = false): void
    {
        $this->assertBelongsTo($table, $partition);

        /*
         * DETACH CONCURRENTLY is illegal inside a transaction block. The prune
         * command never runs in one, so production always takes the concurrent
         * path; a caller that is already in a transaction (the test suite, which
         * wraps each case) falls back to the locking form, where the brief
         * exclusive lock is harmless.
         */
        $concurrently = $this->connection()->transactionLevel() === 0 ? ' CONCURRENTLY' : '';

        $this->statement(sprintf(
            'ALTER TABLE %s DETACH PARTITION %s%s',
            $this->quoteIdentifier($table->value),
            $this->quoteIdentifier($partition),
            $concurrently,
        ));

        if ($drop) {
            $this->statement('DROP TABLE IF EXISTS '.$this->quoteIdentifier($partition));
        }
    }

    public function partitionExists(string $partition): bool
    {
        return $this->connection()->scalar(
            'SELECT count(*) FROM pg_class WHERE relname = ? AND relkind IN (?, ?)',
            [$partition, 'r', 'p']
        ) > 0;
    }

    private function assertBelongsTo(PartitionedTable $table, string $partition): void
    {
        $names = array_column($this->partitions($table), 'name');

        if (! in_array($partition, $names, true)) {
            throw new InvalidArgumentException("[{$partition}] is not a partition of [{$table->value}].");
        }
    }

    /**
     * Pulls a bound out of "FOR VALUES FROM ('...') TO ('...')".
     */
    private function extractBound(string $bounds, int $position): ?CarbonImmutable
    {
        if (preg_match_all("/'([^']+)'/", $bounds, $matches) === false) {
            return null;
        }

        $value = $matches[1][$position - 1] ?? null;

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    private function statement(string $sql): void
    {
        $this->connection()->statement($sql);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * DDL cannot be parameterised, so identifiers are validated rather than bound.
     * All callers pass names derived from the enum and from dates, never from user
     * input, but the guard keeps that guarantee local and checkable.
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Refusing to use [{$identifier}] as an SQL identifier.");
        }

        return '"'.$identifier.'"';
    }

    private function quoteTimestamp(CarbonImmutable $moment): string
    {
        return $this->quoteLiteral($moment->format('Y-m-d H:i:sP'));
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
