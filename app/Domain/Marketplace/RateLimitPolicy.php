<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * How fast a connector may call its marketplace.
 *
 * Expressed as a token bucket rather than a flat ceiling so that a connector can
 * absorb a short burst — a page of deals arriving all at once — without exceeding
 * the sustained rate the marketplace actually enforces.
 */
final readonly class RateLimitPolicy
{
    public function __construct(
        public int $requestsPerMinute,
        public int $burst,
    ) {
        if ($requestsPerMinute < 1) {
            throw new InvalidArgumentException('A rate limit policy needs at least one request per minute.');
        }

        if ($burst < 1) {
            throw new InvalidArgumentException('A rate limit policy needs a burst of at least one.');
        }
    }

    /**
     * Burst defaults to the per-minute allowance, i.e. a full minute's worth of
     * calls may be spent at once and then refills steadily.
     */
    public static function perMinute(int $requestsPerMinute, ?int $burst = null): self
    {
        return new self($requestsPerMinute, $burst ?? $requestsPerMinute);
    }

    public function refillTokensPerSecond(): float
    {
        return $this->requestsPerMinute / 60;
    }

    /**
     * How long to wait for one token to become available, in seconds, rounded up
     * so a caller never retries a fraction too early.
     */
    public function secondsUntilNextToken(): int
    {
        return (int) max(1, ceil(1 / $this->refillTokensPerSecond()));
    }
}
