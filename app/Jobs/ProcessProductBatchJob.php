<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Actions\ProcessProductBatchAction;
use App\Domain\Marketplace\ProductData;
use App\Models\Marketplace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Persists one batch of captured products.
 *
 * Batched rather than one job per product: a catalogue of thirty thousand items
 * would otherwise mean thirty thousand jobs, whose queue overhead dwarfs the work
 * itself. A hundred at a time keeps each job cheap to retry.
 */
final class ProcessProductBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $maxExceptions = 2;

    /**
     * Growing backoff: a database under pressure is not helped by three immediate
     * retries.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60, 180];

    /**
     * @param  list<ProductData>  $products
     */
    public function __construct(
        public readonly int $marketplaceId,
        public readonly array $products,
    ) {
        $this->onQueue('process');
    }

    public function handle(ProcessProductBatchAction $action): void
    {
        $marketplace = Marketplace::query()->find($this->marketplaceId);

        if ($marketplace === null) {
            // Deleted between capture and processing; nothing to attach the
            // products to, and retrying will not bring it back.
            return;
        }

        $result = $action->execute($marketplace, $this->products);

        /*
         * Only the products whose price actually moved go on to be scored. A
         * product read again at an unchanged price would produce an identical
         * verdict, which the dedupe index would reject anyway — so evaluating it
         * is pure queue load.
         */
        foreach ($result->recordedProductIds as $productId) {
            EvaluatePromotionJob::dispatch($productId);
        }

        Log::info('promohub.pipeline.batch_processed', [
            'marketplace' => $marketplace->slug,
            'products_upserted' => $result->productsUpserted,
            'observations_recorded' => $result->observationsRecorded,
            'observations_skipped' => $result->observationsSkipped(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['pipeline', 'process', "marketplace:{$this->marketplaceId}"];
    }
}
