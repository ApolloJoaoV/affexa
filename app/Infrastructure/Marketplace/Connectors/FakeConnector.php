<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace\Connectors;

use App\Domain\Marketplace\Contracts\MarketplaceConnector;
use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Domain\Marketplace\RateLimitPolicy;
use App\Domain\Pricing\Money;
use App\Models\Marketplace;
use Generator;

/**
 * A marketplace that never leaves the process.
 *
 * Exists so the pipeline, the scoring engine and the panel can be exercised end to
 * end without credentials or network — both in the test suite and in local
 * development, where waiting on a real API makes iteration miserable.
 *
 * Output is deterministic for a given seed, so a test asserting on the fifth
 * product keeps asserting on the same product.
 */
final class FakeConnector implements MarketplaceConnector
{
    /**
     * @var list<ProductData>|null products injected by a test, bypassing generation
     */
    private ?array $scripted = null;

    private int $yielded = 0;

    public function __construct(
        private readonly Marketplace $marketplace,
        private readonly int $catalogueSize = 250,
    ) {}

    public function identifier(): string
    {
        return $this->marketplace->slug;
    }

    public function authenticate(): void
    {
        // Nothing to authenticate against.
    }

    public function refreshToken(): void
    {
        // No token to rotate.
    }

    /**
     * Hands the connector an exact list to return, for tests that need specific
     * prices or titles rather than generated ones.
     *
     * @param  list<ProductData>  $products
     */
    public function script(array $products): self
    {
        $this->scripted = $products;

        return $this;
    }

    /**
     * How many products were actually pulled from the generator.
     *
     * Lets a test prove laziness: consuming three products must not have generated
     * the whole catalogue.
     */
    public function yieldedCount(): int
    {
        return $this->yielded;
    }

    /**
     * @return Generator<int, ProductData>
     */
    public function fetchDeals(FetchCriteria $criteria): Generator
    {
        $this->yielded = 0;

        $source = $this->scripted ?? $this->generated($criteria);

        foreach ($source as $product) {
            if ($this->yielded >= $criteria->maxItems) {
                return;
            }

            if ($criteria->minDiscountPercent !== null && $product->discountPercent() < $criteria->minDiscountPercent) {
                continue;
            }

            $this->yielded++;

            yield $product;
        }
    }

    public function fetchProduct(string $externalId): ?ProductData
    {
        if (! str_starts_with($externalId, 'FAKE-')) {
            return null;
        }

        return $this->buildProduct((int) substr($externalId, 5));
    }

    public function buildAffiliateUrl(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'tag=promohub-fake';
    }

    public function rateLimit(): RateLimitPolicy
    {
        return RateLimitPolicy::perMinute($this->marketplace->rate_limit_per_minute);
    }

    /**
     * @return Generator<int, ProductData>
     */
    private function generated(FetchCriteria $criteria): Generator
    {
        for ($index = 1; $index <= $this->catalogueSize; $index++) {
            yield $this->buildProduct($index);
        }
    }

    private function buildProduct(int $index): ProductData
    {
        // Deterministic pseudo-variation: same index always yields the same product.
        $base = 1990 + (($index * 7919) % 400000);
        $discount = ($index * 13) % 70;
        $previous = (int) round($base / (1 - $discount / 100));

        return new ProductData(
            externalId: "FAKE-{$index}",
            title: "Produto de Teste {$index} Edição Especial",
            productUrl: "https://fake.marketplace.test/p/{$index}",
            currentPrice: Money::fromCents($base),
            previousPrice: $discount > 0 ? Money::fromCents($previous) : null,
            brand: 'Marca '.chr(65 + ($index % 26)),
            categoryExternalId: 'CAT-'.(1 + ($index % 5)),
            imageUrl: "https://fake.marketplace.test/img/{$index}.jpg",
            rating: round(3 + ($index % 21) / 10, 1),
            reviewsCount: ($index * 37) % 4000,
            isPrime: $index % 3 === 0,
            hasFreeShipping: $index % 2 === 0,
            inStock: $index % 17 !== 0,
            rawPayload: ['source' => 'fake', 'index' => $index],
        );
    }
}
