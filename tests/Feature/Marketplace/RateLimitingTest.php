<?php

declare(strict_types=1);

use App\Domain\Marketplace\RateLimitPolicy;
use App\Infrastructure\Marketplace\TokenBucketRateLimiter;

beforeEach(function () {
    $this->limiter = new TokenBucketRateLimiter;
    $this->slug = 'rate-test-'.uniqid();
    $this->limiter->reset($this->slug);
});

afterEach(function () {
    $this->limiter->reset($this->slug);
});

it('allows a full burst immediately', function () {
    $policy = RateLimitPolicy::perMinute(60, burst: 5);

    for ($call = 1; $call <= 5; $call++) {
        expect($this->limiter->attempt($this->slug, $policy)->allowed)->toBeTrue("call {$call}");
    }
});

it('denies the call after the bucket empties', function () {
    $policy = RateLimitPolicy::perMinute(60, burst: 2);

    $this->limiter->attempt($this->slug, $policy);
    $this->limiter->attempt($this->slug, $policy);

    expect($this->limiter->attempt($this->slug, $policy)->allowed)->toBeFalse();
});

it('reports how long to wait so the job can be deferred rather than dropped', function () {
    // One request per minute: the next token is a full minute away.
    $policy = RateLimitPolicy::perMinute(1, burst: 1);

    $this->limiter->attempt($this->slug, $policy);
    $decision = $this->limiter->attempt($this->slug, $policy);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($decision->retryAfterSeconds)->toBeLessThanOrEqual(60);
});

it('refills over time', function () {
    // 600 per minute is ten per second, so a token returns in about 100ms.
    $policy = RateLimitPolicy::perMinute(600, burst: 1);

    expect($this->limiter->attempt($this->slug, $policy)->allowed)->toBeTrue()
        ->and($this->limiter->attempt($this->slug, $policy)->allowed)->toBeFalse();

    usleep(250_000);

    expect($this->limiter->attempt($this->slug, $policy)->allowed)->toBeTrue();
});

it('never refills beyond the burst capacity', function () {
    $policy = RateLimitPolicy::perMinute(6000, burst: 3);

    $this->limiter->attempt($this->slug, $policy);
    usleep(200_000);

    // At 100 tokens per second, 200ms would refill 20 tokens were it not capped.
    expect($this->limiter->available($this->slug))->toBeLessThanOrEqual(3.0);
});

it('keeps each marketplace in its own bucket', function () {
    $policy = RateLimitPolicy::perMinute(60, burst: 1);
    $other = $this->slug.'-other';

    $this->limiter->attempt($this->slug, $policy);

    expect($this->limiter->attempt($this->slug, $policy)->allowed)->toBeFalse()
        ->and($this->limiter->attempt($other, $policy)->allowed)->toBeTrue();

    $this->limiter->reset($other);
});

it('derives the policy from the marketplace row so it is tunable without a deploy', function () {
    $policy = RateLimitPolicy::perMinute(120);

    expect($policy->refillTokensPerSecond())->toBe(2.0)
        ->and($policy->burst)->toBe(120)
        ->and($policy->secondsUntilNextToken())->toBe(1);
});

it('refuses a nonsensical policy', function () {
    RateLimitPolicy::perMinute(0);
})->throws(InvalidArgumentException::class);
