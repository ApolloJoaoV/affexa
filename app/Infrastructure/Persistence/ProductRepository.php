<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Marketplace\ProductData;
use App\Models\Marketplace;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class ProductRepository
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * Inserts or updates a batch of products in one statement.
     *
     * ON CONFLICT rather than "select then insert or update": several workers
     * process overlapping pages of the same catalogue, and the check-then-act
     * version loses that race and raises a unique violation on the natural key.
     *
     * @param  list<ProductData>  $products
     * @return array<string, int> external id to product id
     */
    public function upsertBatch(Marketplace $marketplace, array $products): array
    {
        if ($products === []) {
            return [];
        }

        $columns = [
            'marketplace_id', 'external_id', 'identity_hash', 'title', 'normalized_title', 'brand',
            'image_url', 'product_url', 'affiliate_url', 'rating', 'reviews_count', 'is_prime',
            'has_free_shipping', 'in_stock', 'current_price', 'previous_price',
            'lowest_price_ever', 'highest_price_ever', 'raw_payload', 'first_seen_at', 'last_seen_at',
        ];

        $placeholders = [];
        $bindings = [];

        foreach ($products as $product) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, now(), now())';

            array_push(
                $bindings,
                $marketplace->id,
                $product->externalId,
                '\x'.bin2hex($product->identityHash($marketplace->slug)),
                $product->title,
                $product->normalizedTitle(),
                $product->brand,
                $product->imageUrl,
                $product->productUrl,
                $product->affiliateUrl,
                $product->rating,
                $product->reviewsCount,
                $product->isPrime,
                $product->hasFreeShipping,
                $product->inStock,
                $product->currentPrice->toNumericString(),
                $product->previousPrice?->toNumericString(),
                // Seeds for a first sighting; the LEAST/GREATEST below take over
                // from the second onwards.
                $product->currentPrice->toNumericString(),
                $product->currentPrice->toNumericString(),
                json_encode($product->rawPayload, JSON_THROW_ON_ERROR),
            );
        }

        $columnList = implode(', ', $columns);
        $values = implode(', ', $placeholders);

        /*
         * previous_price is our own last observed price, not the marketplace's
         * claimed one, and only moves when the price actually changes. On a first
         * sighting we have nothing better than the connector's figure, which is why
         * a product with no history is always low confidence and never publishes
         * automatically.
         *
         * The all-time bounds are computed in SQL so no read is needed first, which
         * would otherwise be a select per product in every batch.
         */
        $rows = $this->connection()->select(<<<SQL
            INSERT INTO products ({$columnList})
            VALUES {$values}
            ON CONFLICT (marketplace_id, external_id) DO UPDATE SET
                title              = excluded.title,
                normalized_title   = excluded.normalized_title,
                brand              = COALESCE(excluded.brand, products.brand),
                image_url          = COALESCE(excluded.image_url, products.image_url),
                product_url        = excluded.product_url,
                affiliate_url      = COALESCE(excluded.affiliate_url, products.affiliate_url),
                rating             = COALESCE(excluded.rating, products.rating),
                reviews_count      = GREATEST(excluded.reviews_count, products.reviews_count),
                is_prime           = excluded.is_prime,
                has_free_shipping  = excluded.has_free_shipping,
                in_stock           = excluded.in_stock,
                previous_price     = CASE
                    WHEN products.current_price IS DISTINCT FROM excluded.current_price
                        THEN products.current_price
                    ELSE products.previous_price
                END,
                current_price      = excluded.current_price,
                lowest_price_ever  = LEAST(COALESCE(products.lowest_price_ever, excluded.current_price), excluded.current_price),
                highest_price_ever = GREATEST(COALESCE(products.highest_price_ever, excluded.current_price), excluded.current_price),
                raw_payload        = excluded.raw_payload,
                last_seen_at       = now(),
                updated_at         = now(),
                deleted_at         = NULL
            RETURNING id, external_id
        SQL, $bindings);

        $identifiers = [];

        foreach ($rows as $row) {
            /** @var object{id: int, external_id: string} $row */
            $identifiers[$row->external_id] = (int) $row->id;
        }

        return $identifiers;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
