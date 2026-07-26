<?php

declare(strict_types=1);

use App\Domain\Marketplace\Exceptions\CircuitOpenException;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Domain\Marketplace\FetchCriteria;
use App\Infrastructure\Marketplace\CircuitBreaker;
use App\Infrastructure\Marketplace\Connectors\MercadoLivreConnector;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Models\Marketplace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->breaker = new CircuitBreaker;
    $this->marketplace = Marketplace::factory()->create([
        'connector' => MercadoLivreConnector::class,
        'rate_limit_per_minute' => 600,
        'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
    ]);
});

it('stays closed while failures are below the threshold', function () {
    $this->breaker->recordFailure($this->marketplace, 'HTTP 500');

    expect($this->marketplace->consecutive_failures)->toBe(1)
        ->and($this->marketplace->hasOpenCircuit())->toBeFalse();

    // Reaching this line without an exception is the assertion: the circuit is
    // still closed.
    $this->breaker->ensureClosed($this->marketplace->fresh());

    expect(true)->toBeTrue();
});

it('opens once the failure threshold is reached', function () {
    $threshold = $this->breaker->failureThreshold();

    for ($failure = 1; $failure < $threshold; $failure++) {
        expect($this->breaker->recordFailure($this->marketplace, 'HTTP 503'))->toBeFalse();
    }

    expect($this->breaker->recordFailure($this->marketplace, 'HTTP 503'))->toBeTrue()
        ->and($this->marketplace->fresh()->hasOpenCircuit())->toBeTrue();
});

it('refuses calls while open', function () {
    $marketplace = Marketplace::factory()->circuitOpen()->create();

    $this->breaker->ensureClosed($marketplace);
})->throws(CircuitOpenException::class);

it('allows calls again once the cooldown has elapsed', function () {
    $marketplace = Marketplace::factory()->create([
        'consecutive_failures' => 9,
        'circuit_open_until' => now()->subMinute(),
    ]);

    $this->breaker->ensureClosed($marketplace);
})->throwsNoExceptions();

it('clears the failure streak on the next success', function () {
    $this->breaker->recordFailure($this->marketplace, 'HTTP 500');
    $this->breaker->recordFailure($this->marketplace, 'HTTP 500');

    $this->breaker->recordSuccess($this->marketplace);

    $fresh = $this->marketplace->fresh();

    expect($fresh->consecutive_failures)->toBe(0)
        ->and($fresh->circuit_open_until)->toBeNull()
        ->and($fresh->last_error_message)->toBeNull();
});

it('counts concurrent failures without losing any', function () {
    // Increments happen in SQL, not read-modify-write, because several workers
    // fail against the same dead API at once. A lost update would mean the
    // breaker never trips.
    DB::update('UPDATE marketplaces SET consecutive_failures = 3 WHERE id = ?', [$this->marketplace->id]);

    $this->breaker->recordFailure($this->marketplace->fresh(), 'HTTP 502');

    expect($this->marketplace->fresh()->consecutive_failures)->toBe(4);
});

it('truncates an oversized upstream error message', function () {
    $this->breaker->recordFailure($this->marketplace, str_repeat('x', 5000));

    expect(mb_strlen((string) $this->marketplace->fresh()->last_error_message))->toBe(500);
});

it('records a failure when the marketplace returns an error status', function () {
    Http::fake(['*' => Http::response('upstream exploded', 500)]);

    $connector = app(MarketplaceConnectorManager::class)->for($this->marketplace);

    expect(fn () => iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1))))
        ->toThrow(ConnectorException::class);

    expect($this->marketplace->fresh()->consecutive_failures)->toBe(1);
});

it('stops calling the marketplace entirely once the circuit is open', function () {
    Http::fake(['*' => Http::response('upstream exploded', 500)]);

    $connector = app(MarketplaceConnectorManager::class)->for($this->marketplace);
    $threshold = $this->breaker->failureThreshold();

    for ($attempt = 0; $attempt < $threshold; $attempt++) {
        try {
            iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1)));
        } catch (ConnectorException) {
            // expected
        }
    }

    Http::assertSentCount($threshold);

    // The next attempt must not reach the network at all: that is the entire
    // point, sparing the queue from jobs certain to fail.
    expect(fn () => iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1))))
        ->toThrow(CircuitOpenException::class);

    Http::assertSentCount($threshold);
});
