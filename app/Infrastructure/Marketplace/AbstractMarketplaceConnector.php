<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace;

use App\Domain\Marketplace\Contracts\MarketplaceConnector;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Domain\Marketplace\Exceptions\RateLimitExceededException;
use App\Domain\Marketplace\RateLimitPolicy;
use App\Models\Marketplace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Everything a connector needs but should not have to reimplement: rate limiting,
 * the circuit breaker, retries, and telemetry.
 *
 * A subclass is left with the only part that is genuinely marketplace specific —
 * authentication, pagination and turning a payload into ProductData.
 */
abstract class AbstractMarketplaceConnector implements MarketplaceConnector
{
    public function __construct(
        protected readonly Marketplace $marketplace,
        protected readonly TokenBucketRateLimiter $rateLimiter,
        protected readonly CircuitBreaker $circuitBreaker,
        protected readonly ApiCallLogger $callLogger,
    ) {}

    public function identifier(): string
    {
        return $this->marketplace->slug;
    }

    /**
     * Taken from the marketplace row, so an operator can throttle a misbehaving
     * integration from the panel without a deploy.
     */
    public function rateLimit(): RateLimitPolicy
    {
        return RateLimitPolicy::perMinute($this->marketplace->rate_limit_per_minute);
    }

    /**
     * Most marketplaces need no affiliate rewriting beyond a tag; those that do
     * override this.
     */
    public function buildAffiliateUrl(string $url): string
    {
        return $url;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    protected function get(string $url, array $query = []): array
    {
        /** @var array<mixed> */
        return $this->send('GET', $url, ['query' => $query]);
    }

    /**
     * A GET where the listed statuses are answers rather than failures.
     *
     * A product that 404s has been delisted, which is ordinary and must not count
     * against the circuit breaker: the marketplace responded correctly.
     *
     * @param  array<string, mixed>  $query
     * @param  list<int>  $tolerate
     * @return array<mixed>|null
     */
    protected function getTolerating(string $url, array $query = [], array $tolerate = [404]): ?array
    {
        return $this->send('GET', $url, ['query' => $query], $tolerate);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    protected function post(string $url, array $payload = [], bool $asForm = false): array
    {
        /** @var array<mixed> */
        return $this->send('POST', $url, [$asForm ? 'form_params' : 'json' => $payload]);
    }

    /**
     * The single funnel every outbound call passes through.
     *
     * Order matters: the circuit is checked before a token is spent, so a
     * marketplace that is already known to be down does not drain the bucket that
     * a healthy retry will need later.
     *
     * @param  array<string, mixed>  $options
     * @param  list<int>  $tolerate  statuses treated as an answer, yielding null
     * @return array<mixed>|null
     *
     * @throws RateLimitExceededException when the bucket is empty; the caller
     *                                    releases the job with the given delay
     * @throws ConnectorException on any failed call, after recording the failure
     */
    protected function send(string $method, string $url, array $options = [], array $tolerate = []): ?array
    {
        $this->circuitBreaker->ensureClosed($this->marketplace);

        $decision = $this->rateLimiter->attempt($this->identifier(), $this->rateLimit());

        if (! $decision->allowed) {
            throw new RateLimitExceededException($this->identifier(), $decision->retryAfterSeconds);
        }

        $startedAt = microtime(true);
        $response = null;
        $failure = null;

        try {
            $response = $this->request()->send($method, $url, $options);
        } catch (ConnectionException $exception) {
            // Never got a response: DNS, TLS or timeout.
            $failure = $exception;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->callLogger->record(
            marketplaceId: $this->marketplace->id,
            endpoint: $this->endpointFor($url),
            httpStatus: $response?->status(),
            durationMs: $durationMs,
            requestSignature: $method.' '.$url.json_encode($options['query'] ?? []),
            responseBody: $response?->body(),
        );

        if ($failure !== null) {
            $this->recordFailureAndThrow($failure->getMessage(), $failure);
        }

        /** @var Response $response */
        if (in_array($response->status(), $tolerate, true)) {
            // The marketplace answered, so the integration is healthy even though
            // this particular resource is gone.
            $this->circuitBreaker->recordSuccess($this->marketplace);

            return null;
        }

        if ($response->failed()) {
            $this->recordFailureAndThrow(
                "HTTP {$response->status()} from {$this->endpointFor($url)}: ".mb_substr($response->body(), 0, 200)
            );
        }

        $this->circuitBreaker->recordSuccess($this->marketplace);

        return $this->decode($response);
    }

    /**
     * @throws ConnectorException
     */
    private function recordFailureAndThrow(string $reason, ?Throwable $previous = null): never
    {
        $opened = $this->circuitBreaker->recordFailure($this->marketplace, $reason);

        throw new ConnectorException(
            $opened
                ? "{$reason} (circuit opened for {$this->identifier()})"
                : $reason,
            previous: $previous,
        );
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders($this->defaultHeaders())
            ->timeout((int) config('promohub.http.timeout_seconds', 15))
            ->connectTimeout((int) config('promohub.http.connect_timeout_seconds', 5))
            ->retry(
                times: (int) config('promohub.http.retries', 3),
                sleepMilliseconds: function (int $attempt): int {
                    // Exponential, so a marketplace that is briefly overloaded is
                    // given progressively more room instead of being hammered.
                    return (int) config('promohub.http.retry_base_delay_ms', 250) * (2 ** ($attempt - 1));
                },
                when: function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                },
                throw: false,
            );
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['Accept' => 'application/json'];
    }

    /**
     * @return array<mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new ConnectorException(
                "Expected a JSON document from {$this->identifier()}, got ".get_debug_type($decoded).'.'
            );
        }

        return $decoded;
    }

    /**
     * Path only. The full URL would make every distinct query string its own
     * endpoint and render the telemetry useless for grouping.
     */
    private function endpointFor(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) ?: $url;
    }
}
