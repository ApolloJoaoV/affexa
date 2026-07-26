<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

/**
 * The token bucket is empty.
 *
 * Carries how long to wait so the caller can release the job back onto the queue
 * with that delay. The work is postponed, never dropped.
 */
final class RateLimitExceededException extends ConnectorException
{
    public function __construct(
        public readonly string $marketplace,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            "Rate limit reached for [{$marketplace}]; retry in {$retryAfterSeconds}s."
        );
    }
}
