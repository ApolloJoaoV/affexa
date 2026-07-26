<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mutual exclusion between workers using PostgreSQL advisory locks.
 *
 * Used where losing the race must not merely be inefficient but is actively
 * harmful — token rotation being the case in point, since two workers refreshing
 * at once will each invalidate the other's brand new token.
 */
final class AdvisoryLock
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * Runs the callback while holding a transaction scoped lock on $name, or returns
     * null immediately if another worker holds it.
     *
     * The transactional variant is deliberate. pg_advisory_lock is session scoped
     * and must be released explicitly, so a worker killed mid-task leaks the lock
     * until its connection is reaped — and with Horizon restarting workers, that
     * happens. pg_try_advisory_xact_lock is released by the transaction ending,
     * whether it commits, rolls back or the process dies.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn|null null when the lock was not acquired
     */
    public function attempt(string $name, Closure $callback): mixed
    {
        $key = $this->keyFor($name);

        return $this->connection()->transaction(function (ConnectionInterface $connection) use ($key, $callback): mixed {
            /** @var object{locked: bool} $result */
            $result = $connection->selectOne('SELECT pg_try_advisory_xact_lock(?) AS locked', [$key]);

            if (! $result->locked) {
                return null;
            }

            return $callback();
        });
    }

    public function isHeldByAnyone(string $name): bool
    {
        return $this->connection()->scalar(
            "SELECT count(*) > 0 FROM pg_locks WHERE locktype = 'advisory' AND ((classid::bigint << 32) | objid::bigint) = ?",
            [$this->keyFor($name)]
        ) === true;
    }

    /**
     * Advisory locks are keyed by integer, so the name is hashed down to one.
     *
     * crc32 is used rather than a cryptographic hash because the value must fit a
     * bigint and only needs to be stable and well distributed, not unguessable.
     */
    private function keyFor(string $name): int
    {
        return crc32($name);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
