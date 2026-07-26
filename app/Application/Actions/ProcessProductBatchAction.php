<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Domain\Marketplace\ProductData;
use App\Infrastructure\Persistence\PriceHistoryRepository;
use App\Infrastructure\Persistence\ProductRepository;
use App\Models\Marketplace;

/**
 * Persists a batch of captured products and their price observations.
 *
 * Two statements for the whole batch, not two per product: at tens of thousands of
 * products a day the difference between a round trip per row and a round trip per
 * hundred rows is the difference between a pipeline that keeps up and one that
 * does not.
 */
final class ProcessProductBatchAction
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly PriceHistoryRepository $priceHistory,
    ) {}

    /**
     * @param  list<ProductData>  $products
     */
    public function execute(Marketplace $marketplace, array $products): ProcessProductBatchResult
    {
        if ($products === []) {
            return new ProcessProductBatchResult(0, 0);
        }

        $identifiers = $this->products->upsertBatch($marketplace, $products);

        $observations = [];

        foreach ($products as $product) {
            $productId = $identifiers[$product->externalId] ?? null;

            if ($productId === null) {
                continue;
            }

            $observations[] = [
                'product_id' => $productId,
                'marketplace_id' => $marketplace->id,
                'price' => $product->currentPrice->toNumericString(),
                'list_price' => $product->listPrice?->toNumericString(),
                'in_stock' => $product->inStock,
            ];
        }

        $recorded = $this->priceHistory->recordObservations($observations);

        return new ProcessProductBatchResult(
            productsUpserted: count($identifiers),
            observationsRecorded: count($recorded),
            recordedProductIds: $recorded,
        );
    }
}
