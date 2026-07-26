<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use Carbon\CarbonImmutable;

/**
 * The circuit breaker is holding this marketplace out of rotation after repeated
 * failures. Raised instead of attempting a call that is expected to fail, so a
 * dead API cannot consume the whole queue.
 */
final class CircuitOpenException extends ConnectorException
{
    public function __construct(
        public readonly string $marketplace,
        public readonly CarbonImmutable $openUntil,
    ) {
        parent::__construct(
            "The circuit for [{$marketplace}] is open until {$openUntil->toDateTimeString()}."
        );
    }

    public function secondsUntilRetry(): int
    {
        return max(1, (int) CarbonImmutable::now()->diffInSeconds($this->openUntil, absolute: false));
    }
}
