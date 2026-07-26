<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Domain\Marketplace\RateLimitPolicy;

/**
 * Everything the core needs from a marketplace.
 *
 * Adding a marketplace means implementing this interface and registering the class
 * name in the marketplaces table. No core code changes, no match statement to
 * extend.
 */
interface MarketplaceConnector
{
    /**
     * Stable identifier, matching the marketplace slug.
     */
    public function identifier(): string;

    /**
     * Obtains credentials for subsequent calls, storing whatever tokens result.
     */
    public function authenticate(): void;

    /**
     * Renews the access token. Called preemptively before expiry, not reactively
     * on a 401, so a fetch run is never interrupted mid-pagination.
     */
    public function refreshToken(): void;

    /**
     * Deals matching the criteria.
     *
     * Must be implemented as a generator. A marketplace can return tens of
     * thousands of items across hundreds of pages; materialising that into an
     * array would exhaust the worker's memory before the first product is ever
     * processed. Yielding also means a caller that stops early stops the paging.
     *
     * @return iterable<int, ProductData>
     */
    public function fetchDeals(FetchCriteria $criteria): iterable;

    /**
     * A single product, or null when the marketplace no longer lists it.
     */
    public function fetchProduct(string $externalId): ?ProductData;

    /**
     * Rewrites a product URL into its affiliate form.
     */
    public function buildAffiliateUrl(string $url): string;

    /**
     * The call rate this marketplace tolerates.
     */
    public function rateLimit(): RateLimitPolicy;
}
