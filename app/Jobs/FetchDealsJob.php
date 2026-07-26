<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Marketplace\Exceptions\CircuitOpenException;
use App\Domain\Marketplace\Exceptions\RateLimitExceededException;
use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Models\Marketplace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Walks a marketplace's deal feed and hands batches to the processing queue.
 *
 * Unique per marketplace: the scheduler ticks on a fixed interval, and a run that
 * outlives its interval must not have a second copy start alongside it and
 * duplicate every API call.
 */
final class FetchDealsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $maxExceptions = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    /**
     * Long enough to cover a slow full run, short enough that a worker killed
     * mid-fetch does not lock the marketplace out until someone notices.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $marketplaceId,
        public readonly ?int $maxItems = null,
    ) {
        // The long connection: a full catalogue walk outlives the default
        // retry_after, which would otherwise hand this job to a second worker
        // while it is still paging.
        $this->onConnection('redis_long')->onQueue('fetch');
    }

    public function uniqueId(): string
    {
        return "fetch-deals:{$this->marketplaceId}";
    }

    public function handle(MarketplaceConnectorManager $connectors): void
    {
        $marketplace = Marketplace::query()->find($this->marketplaceId);

        if ($marketplace === null || ! $marketplace->is_active) {
            return;
        }

        if ($marketplace->hasOpenCircuit()) {
            // The breaker already decided this marketplace is down. Burning a
            // queue slot to confirm it helps nobody.
            Log::info('promohub.pipeline.fetch_skipped_circuit_open', ['marketplace' => $marketplace->slug]);

            return;
        }

        $criteria = $this->criteriaFor($marketplace);
        $batchSize = max(1, (int) config('promohub.pipeline.batch_size', 100));

        $batch = [];
        $captured = 0;

        try {
            foreach ($connectors->for($marketplace)->fetchDeals($criteria) as $product) {
                $batch[] = $product;
                $captured++;

                if (count($batch) >= $batchSize) {
                    $this->dispatchBatch($marketplace, $batch);
                    $batch = [];
                }
            }
        } catch (RateLimitExceededException $exception) {
            /*
             * Whatever was already captured is still worth persisting, so it is
             * dispatched before the job steps aside. The run then resumes after the
             * bucket refills rather than being discarded.
             */
            $this->dispatchBatch($marketplace, $batch);

            Log::info('promohub.pipeline.fetch_rate_limited', [
                'marketplace' => $marketplace->slug,
                'captured' => $captured,
                'retry_after' => $exception->retryAfterSeconds,
            ]);

            $this->release($exception->retryAfterSeconds);

            return;
        } catch (CircuitOpenException $exception) {
            $this->dispatchBatch($marketplace, $batch);
            $this->release($exception->secondsUntilRetry());

            return;
        }

        $this->dispatchBatch($marketplace, $batch);

        $marketplace->forceFill(['last_fetched_at' => now()])->save();

        Log::info('promohub.pipeline.fetch_completed', [
            'marketplace' => $marketplace->slug,
            'captured' => $captured,
        ]);
    }

    /**
     * @param  list<ProductData>  $batch
     */
    private function dispatchBatch(Marketplace $marketplace, array $batch): void
    {
        if ($batch === []) {
            return;
        }

        ProcessProductBatchJob::dispatch($marketplace->id, $batch);
    }

    private function criteriaFor(Marketplace $marketplace): FetchCriteria
    {
        $categories = $marketplace->setting('category_external_ids', []);

        return new FetchCriteria(
            categoryExternalIds: is_array($categories) ? array_values(array_map(strval(...), $categories)) : [],
            maxItems: $this->maxItems ?? (int) config('promohub.pipeline.max_items_per_fetch', 2000),
        );
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['pipeline', 'fetch', "marketplace:{$this->marketplaceId}"];
    }
}
