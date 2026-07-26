<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

final readonly class RateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public int $retryAfterSeconds,
    ) {}

    public static function allowed(): self
    {
        return new self(true, 0);
    }

    public static function denied(int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds));
    }
}
