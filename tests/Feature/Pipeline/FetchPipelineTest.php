<?php

declare(strict_types=1);

use App\Application\Actions\ProcessProductBatchAction;
use App\Infrastructure\Marketplace\Connectors\FakeConnector;
use App\Infrastructure\Marketplace\Connectors\MercadoLivreConnector;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Infrastructure\Marketplace\TokenBucketRateLimiter;
use App\Jobs\FetchDealsJob;
use App\Jobs\ProcessProductBatchJob;
use App\Models\Marketplace;
use App\Models\PriceHistory;
use App\Models\Product;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->marketplace = Marketplace::factory()->create([
        'slug' => 'pipeline-'.uniqid(),
        'connector' => FakeConnector::class,
    ]);
});

it('splits a capture into batches rather than a job per product', function () {
    Bus::fake([ProcessProductBatchJob::class]);
    config()->set('promohub.pipeline.batch_size', 25);

    (new FetchDealsJob($this->marketplace->id, maxItems: 100))->handle(app(MarketplaceConnectorManager::class));

    // A hundred products become four jobs, not a hundred: queue overhead would
    // otherwise dwarf the work itself.
    Bus::assertDispatchedTimes(ProcessProductBatchJob::class, 4);
});

it('dispatches a final partial batch', function () {
    Bus::fake([ProcessProductBatchJob::class]);
    config()->set('promohub.pipeline.batch_size', 30);

    (new FetchDealsJob($this->marketplace->id, maxItems: 70))->handle(app(MarketplaceConnectorManager::class));

    // 30 + 30 + 10: the remainder must not be dropped on the floor.
    Bus::assertDispatchedTimes(ProcessProductBatchJob::class, 3);
});

it('persists captured products end to end', function () {
    config()->set('promohub.pipeline.batch_size', 10);

    (new FetchDealsJob($this->marketplace->id, maxItems: 20))->handle(app(MarketplaceConnectorManager::class));

    expect(Product::where('marketplace_id', $this->marketplace->id)->count())->toBe(20)
        ->and(PriceHistory::count())->toBe(20)
        ->and($this->marketplace->fresh()->last_fetched_at)->not->toBeNull();
});

it('is unique per marketplace so a slow run is never doubled', function () {
    $job = new FetchDealsJob($this->marketplace->id);

    expect($job->uniqueId())->toBe("fetch-deals:{$this->marketplace->id}")
        ->and($job->uniqueFor)->toBeGreaterThan(0);
});

it('runs on the long connection, whose retry_after outlasts a full catalogue walk', function () {
    $job = new FetchDealsJob($this->marketplace->id);

    // job timeout < connection retry_after, or a still-running capture is handed
    // to a second worker and every API call happens twice.
    expect($job->connection)->toBe('redis_long')
        ->and($job->queue)->toBe('fetch')
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis_long.retry_after'));
});

it('keeps every queue job timeout below its connection retry_after', function () {
    expect((new ProcessProductBatchJob(1, []))->timeout)
        ->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('skips a marketplace whose circuit is open without touching the network', function () {
    Http::fake();
    Bus::fake([ProcessProductBatchJob::class]);

    $marketplace = Marketplace::factory()->circuitOpen()->create([
        'connector' => MercadoLivreConnector::class,
        'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
    ]);

    (new FetchDealsJob($marketplace->id))->handle(app(MarketplaceConnectorManager::class));

    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('skips an inactive marketplace', function () {
    Bus::fake([ProcessProductBatchJob::class]);
    $this->marketplace->update(['is_active' => false]);

    (new FetchDealsJob($this->marketplace->id))->handle(app(MarketplaceConnectorManager::class));

    Bus::assertNothingDispatched();
});

it('tolerates a marketplace deleted between capture and processing', function () {
    $job = new ProcessProductBatchJob(999_999, [productData('A1', '10.00')]);

    // Returning quietly is the correct behaviour: retrying will not bring the
    // marketplace back, so the batch is dropped rather than looping to failure.
    $job->handle(app(ProcessProductBatchAction::class));

    expect(Product::count())->toBe(0);
});

it('queues capture only for marketplaces whose interval has elapsed', function () {
    Queue::fake();

    $due = Marketplace::factory()->create([
        'slug' => 'due-'.uniqid(),
        'fetch_interval_minutes' => 60,
        'last_fetched_at' => now()->subHours(2),
    ]);

    $notDue = Marketplace::factory()->create([
        'slug' => 'fresh-'.uniqid(),
        'fetch_interval_minutes' => 60,
        'last_fetched_at' => now()->subMinutes(5),
    ]);

    $this->artisan('promohub:marketplaces:fetch')->assertSuccessful();

    Queue::assertPushed(FetchDealsJob::class, fn (FetchDealsJob $job): bool => $job->marketplaceId === $due->id);
    Queue::assertNotPushed(FetchDealsJob::class, fn (FetchDealsJob $job): bool => $job->marketplaceId === $notDue->id);
});

it('never queues a marketplace held out by the circuit breaker', function () {
    Queue::fake();

    $open = Marketplace::factory()->circuitOpen()->create(['last_fetched_at' => now()->subDay()]);

    $this->artisan('promohub:marketplaces:fetch')->assertSuccessful();

    Queue::assertNotPushed(FetchDealsJob::class, fn (FetchDealsJob $job): bool => $job->marketplaceId === $open->id);
});

it('queues a named marketplace regardless of its interval', function () {
    Queue::fake();

    $this->marketplace->update(['last_fetched_at' => now()]);

    $this->artisan('promohub:marketplaces:fetch', ['slug' => $this->marketplace->slug])->assertSuccessful();

    Queue::assertPushed(FetchDealsJob::class);
});

it('defers the run and keeps what it captured when the rate limit is hit', function () {
    $marketplace = Marketplace::factory()->create([
        'slug' => 'limited-'.uniqid(),
        'connector' => MercadoLivreConnector::class,
        'rate_limit_per_minute' => 1,
        'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
    ]);

    (new TokenBucketRateLimiter)->reset($marketplace->slug);

    Http::fake(['*' => Http::response([
        'paging' => ['total' => 500],
        'results' => [
            ['id' => 'MLB1', 'title' => 'Um', 'price' => 10, 'permalink' => 'https://x.test/1', 'available_quantity' => 1],
        ],
    ])]);

    Bus::fake([ProcessProductBatchJob::class]);

    $job = new FetchDealsJob($marketplace->id, maxItems: 50);
    $job->handle(app(MarketplaceConnectorManager::class));

    // The single token buys one page. What it yielded is still handed on rather
    // than thrown away, and the run resumes once the bucket refills.
    Bus::assertDispatchedTimes(ProcessProductBatchJob::class, 1);

    (new TokenBucketRateLimiter)->reset($marketplace->slug);
});
