<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace;

use App\Domain\Marketplace\RateLimitDecision;
use App\Domain\Marketplace\RateLimitPolicy;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

/**
 * Per-marketplace token bucket held in Redis.
 *
 * Redis rather than the database because every worker consults it before every
 * outbound call, and that write load has no business landing on a PostgreSQL
 * instance already absorbing price history inserts.
 *
 * A denied attempt reports how long to wait. Callers release the job back onto the
 * queue with that delay rather than discarding it, so a burst is postponed and
 * never lost.
 */
final class TokenBucketRateLimiter
{
    /**
     * Refill and consume in one atomic step.
     *
     * This has to be a script: read-modify-write from PHP would let two workers
     * both read the same remaining token and both spend it, which is precisely
     * the overrun the marketplace would rate limit us for.
     */
    private const SCRIPT = <<<'LUA'
        local tokens_key = KEYS[1]
        local stamp_key  = KEYS[2]
        local rate       = tonumber(ARGV[1])
        local capacity   = tonumber(ARGV[2])
        local now        = tonumber(ARGV[3])
        local requested  = tonumber(ARGV[4])

        local tokens = tonumber(redis.call('get', tokens_key))
        local stamp  = tonumber(redis.call('get', stamp_key))

        if tokens == nil or stamp == nil then
            tokens = capacity
            stamp = now
        end

        tokens = math.min(capacity, tokens + math.max(0, now - stamp) * rate)

        local allowed = tokens >= requested
        if allowed then
            tokens = tokens - requested
        end

        -- Expiry is twice a full refill, so an idle bucket eventually disappears
        -- instead of pinning a key per marketplace forever.
        local ttl = math.max(2, math.ceil((capacity / rate) * 2))
        redis.call('set', tokens_key, tokens, 'EX', ttl)
        redis.call('set', stamp_key, now, 'EX', ttl)

        if allowed then
            return {1, 0}
        end

        return {0, math.ceil((requested - tokens) / rate)}
    LUA;

    public function attempt(string $marketplaceSlug, RateLimitPolicy $policy, int $tokens = 1): RateLimitDecision
    {
        /*
         * Typed against the phpredis connection rather than the facade: the facade
         * proxies to the raw \Redis class, which advertises the extension's
         * argument order instead of Laravel's (script, numKeys, ...args).
         *
         * Keys are only ever touched through this script, so the phpredis quirk of
         * not applying the configured prefix to EVAL keys is harmless here.
         */
        /** @var array{0: int, 1: int} $result */
        $result = $this->connection()->eval(
            self::SCRIPT,
            2,
            $this->tokensKey($marketplaceSlug),
            $this->stampKey($marketplaceSlug),
            (string) $policy->refillTokensPerSecond(),
            (string) $policy->burst,
            (string) microtime(true),
            (string) $tokens,
        );

        [$allowed, $retryAfter] = $result;

        return $allowed === 1
            ? RateLimitDecision::allowed()
            : RateLimitDecision::denied((int) $retryAfter);
    }

    /**
     * Tokens currently available. Exposed for the health check and for tests; the
     * hot path uses attempt().
     */
    public function available(string $marketplaceSlug): float
    {
        $tokens = Redis::get($this->tokensKey($marketplaceSlug));

        return $tokens === null ? 0.0 : (float) $tokens;
    }

    public function reset(string $marketplaceSlug): void
    {
        Redis::del($this->tokensKey($marketplaceSlug), $this->stampKey($marketplaceSlug));
    }

    /**
     * The project runs phpredis, declared in config/database.php; predis has a
     * different EVAL argument order and is not supported by this limiter.
     */
    private function connection(): PhpRedisConnection
    {
        /** @var PhpRedisConnection */
        return Redis::connection();
    }

    private function tokensKey(string $marketplaceSlug): string
    {
        return "promohub:rate_limit:{$marketplaceSlug}:tokens";
    }

    private function stampKey(string $marketplaceSlug): string
    {
        return "promohub:rate_limit:{$marketplaceSlug}:stamp";
    }
}
