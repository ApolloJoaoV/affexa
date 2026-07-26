<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Illuminate\Database\Events\ConnectionEstablished;
use InvalidArgumentException;

/**
 * Applies per-connection PostgreSQL session timeouts as soon as a connection is
 * opened.
 *
 * Without these, a single pathological query can hold a connection open
 * indefinitely. With max_connections capped and dozens of Horizon workers each
 * holding their own connection, that exhausts the pool and takes the whole
 * application down rather than just failing one request.
 *
 * The values live in the connection config so that the web tier (short) and the
 * queue workers (long) can differ without any code branching.
 */
final class ConfigureConnectionTimeouts
{
    /**
     * PostgreSQL interval literals we accept, e.g. "30s", "500ms", "2min", "0".
     */
    private const TIMEOUT_PATTERN = '/^(0|\d+(ms|s|min))$/';

    /**
     * @var list<string>
     */
    private const SETTINGS = [
        'statement_timeout',
        'lock_timeout',
        'idle_in_transaction_session_timeout',
    ];

    public function handle(ConnectionEstablished $event): void
    {
        $connection = $event->connection;

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::SETTINGS as $setting) {
            $value = $connection->getConfig($setting);

            if (! is_string($value) || $value === '') {
                continue;
            }

            /*
             * set_config() is used rather than SET because it is a function call
             * and therefore accepts bindings; SET only takes literals. That keeps
             * a value coming from the environment out of the SQL string entirely.
             */
            $connection->select('SELECT set_config(?, ?, false)', [
                $setting,
                $this->assertValidInterval($setting, $value),
            ]);
        }
    }

    /**
     * Fails loudly at connect time on a malformed value, rather than letting
     * PostgreSQL raise an opaque error on every single request.
     */
    private function assertValidInterval(string $setting, string $value): string
    {
        if (preg_match(self::TIMEOUT_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Invalid PostgreSQL timeout [{$value}] configured for [{$setting}]. ".
                "Expected a literal such as '30s', '500ms' or '2min'."
            );
        }

        return $value;
    }
}
