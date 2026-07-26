<?php

declare(strict_types=1);

use App\Domain\Marketplace\Exceptions\CircuitOpenException;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Domain\Marketplace\Exceptions\RateLimitExceededException;
use App\Domain\Marketplace\FetchCriteria;
use App\Infrastructure\Marketplace\ApiCallLogger;
use App\Infrastructure\Marketplace\Connectors\MercadoLivreConnector;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Infrastructure\Marketplace\TokenBucketRateLimiter;
use App\Models\Marketplace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->marketplace = Marketplace::factory()->create([
        'slug' => 'telemetry-'.uniqid(),
        'connector' => MercadoLivreConnector::class,
        'rate_limit_per_minute' => 600,
        'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
    ]);

    (new TokenBucketRateLimiter)->reset($this->marketplace->slug);
    $this->connector = app(MarketplaceConnectorManager::class)->for($this->marketplace);
});

afterEach(function () {
    (new TokenBucketRateLimiter)->reset($this->marketplace->slug);
});

it('records every outbound call', function () {
    Http::fake(['*' => Http::response(['paging' => ['total' => 0], 'results' => []])]);

    iterator_to_array($this->connector->fetchDeals(new FetchCriteria(maxItems: 1)));

    $log = DB::table('api_call_logs')->where('marketplace_id', $this->marketplace->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->endpoint)->toBe('/sites/MLB/search')
        ->and($log->http_status)->toBe(200)
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0)
        // Successful calls store no body: keeping every response would dwarf the
        // price history table.
        ->and($log->response_excerpt)->toBeNull();
});

it('keeps an excerpt of the body when a call fails', function () {
    Http::fake(['*' => Http::response(str_repeat('boom ', 500), 502)]);

    try {
        iterator_to_array($this->connector->fetchDeals(new FetchCriteria(maxItems: 1)));
    } catch (ConnectorException) {
        // expected
    }

    $log = DB::table('api_call_logs')->where('marketplace_id', $this->marketplace->id)->first();

    expect($log->http_status)->toBe(502)
        ->and($log->response_excerpt)->not->toBeNull()
        ->and(mb_strlen($log->response_excerpt))->toBe(1000);
});

it('groups telemetry by path, not by query string', function () {
    Http::fake(['*' => Http::response(['paging' => ['total' => 0], 'results' => []])]);

    iterator_to_array($this->connector->fetchDeals(new FetchCriteria(categoryExternalIds: ['MLB1', 'MLB2'], maxItems: 2)));

    $endpoints = DB::table('api_call_logs')
        ->where('marketplace_id', $this->marketplace->id)
        ->distinct()
        ->pluck('endpoint');

    // Two different queries, one endpoint: otherwise every query string would be
    // its own row and the p95 per endpoint would be meaningless.
    expect($endpoints)->toHaveCount(1)
        ->and($endpoints->first())->toBe('/sites/MLB/search');
});

it('defers the job instead of dropping it when the rate limit is hit', function () {
    $this->marketplace->update(['rate_limit_per_minute' => 1]);
    app(MarketplaceConnectorManager::class)->flush();
    $connector = app(MarketplaceConnectorManager::class)->for($this->marketplace->fresh());

    Http::fake(['*' => Http::response(['paging' => ['total' => 100], 'results' => [
        ['id' => 'MLB1', 'title' => 'A', 'price' => 10, 'permalink' => 'https://x.test/1', 'available_quantity' => 1],
    ]])]);

    // The single token is spent by the first page.
    iterator_to_array($connector->fetchDeals(new FetchCriteria(pageSize: 1, maxItems: 1)));

    try {
        iterator_to_array($connector->fetchDeals(new FetchCriteria(pageSize: 1, maxItems: 1)));
        $this->fail('Expected the rate limiter to refuse the second run.');
    } catch (RateLimitExceededException $exception) {
        // The delay is what the job uses to release itself back onto the queue.
        expect($exception->retryAfterSeconds)->toBeGreaterThan(0)
            ->and($exception->marketplace)->toBe($this->marketplace->slug);
    }

    // Crucially, no second HTTP call was made: the budget is protected before the
    // network is touched.
    Http::assertSentCount(1);
});

it('does not spend a rate limit token when the circuit is already open', function () {
    $this->marketplace->update(['circuit_open_until' => now()->addMinutes(10), 'consecutive_failures' => 9]);
    app(MarketplaceConnectorManager::class)->flush();
    $connector = app(MarketplaceConnectorManager::class)->for($this->marketplace->fresh());

    $limiter = new TokenBucketRateLimiter;
    $limiter->reset($this->marketplace->slug);

    try {
        iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1)));
    } catch (CircuitOpenException) {
        // expected
    }

    // The bucket was never touched, so a healthy retry later has its full budget.
    expect($limiter->available($this->marketplace->slug))->toBe(0.0);
});

it('reports p95 latency per marketplace for the dashboard', function () {
    Http::fake(['*' => Http::response(['paging' => ['total' => 0], 'results' => []])]);

    iterator_to_array($this->connector->fetchDeals(new FetchCriteria(maxItems: 1)));

    $percentiles = (new ApiCallLogger)->latencyPercentiles();
    $mine = collect($percentiles)->firstWhere('marketplace_id', $this->marketplace->id);

    expect($mine)->not->toBeNull()
        ->and($mine['calls'])->toBe(1)
        ->and($mine['error_rate'])->toBe(0.0);
});
