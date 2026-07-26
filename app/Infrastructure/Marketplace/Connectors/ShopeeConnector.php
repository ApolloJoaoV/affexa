<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace\Connectors;

use App\Domain\Marketplace\Exceptions\AuthenticationException;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Domain\Pricing\Money;
use App\Infrastructure\Marketplace\AbstractMarketplaceConnector;
use Generator;

/**
 * Shopee, through the Affiliate Open API.
 *
 * Requests are GraphQL over POST, authenticated by a SHA-256 signature over
 * appId + timestamp + payload + secret. There is no token exchange, so nothing to
 * store or rotate.
 *
 * Of the three connectors this is the least certain: Shopee's affiliate platform
 * documents its schema less stably than Amazon or Mercado Livre, and the field
 * names below reflect the productOfferV2 shape as documented rather than as
 * observed. Verify against a live key before trusting the mapping — the fixtures
 * prove the parsing, not the contract.
 */
final class ShopeeConnector extends AbstractMarketplaceConnector
{
    private const DEFAULT_ENDPOINT = 'https://open-api.affiliate.shopee.com.br/graphql';

    /**
     * Shopee caps a page at 50 offers.
     */
    private const MAX_PAGE_SIZE = 50;

    private const OFFER_QUERY = <<<'GRAPHQL'
        query ProductOffer($page: Int!, $limit: Int!, $listType: Int) {
          productOfferV2(page: $page, limit: $limit, listType: $listType) {
            nodes {
              itemId
              productName
              price
              priceMin
              priceMax
              priceDiscountRate
              imageUrl
              productLink
              offerLink
              ratingStar
              sales
              shopName
              productCatIds
            }
            pageInfo { page limit hasNextPage }
          }
        }
    GRAPHQL;

    public function authenticate(): void
    {
        // Validated eagerly so a misconfiguration is reported at setup rather than
        // as a signature rejection mid fetch.
        $this->credentials();
    }

    public function refreshToken(): void
    {
        // Signature based: no token exists to rotate.
    }

    /**
     * @return Generator<int, ProductData>
     */
    public function fetchDeals(FetchCriteria $criteria): Generator
    {
        $delivered = 0;
        $page = 1;
        $limit = min($criteria->pageSize, self::MAX_PAGE_SIZE);

        while ($delivered < $criteria->maxItems) {
            /** @var array{data?: array{productOfferV2?: array{nodes?: list<array<string, mixed>>, pageInfo?: array{hasNextPage?: bool}}}} $response */
            $response = $this->query(self::OFFER_QUERY, [
                'page' => $page,
                'limit' => min($limit, $criteria->maxItems - $delivered),
                'listType' => 0,
            ]);

            $offer = $response['data']['productOfferV2'] ?? [];
            $nodes = $offer['nodes'] ?? [];

            if ($nodes === []) {
                return;
            }

            foreach ($nodes as $node) {
                $product = $this->toProductData($node);

                if ($product === null) {
                    continue;
                }

                if ($criteria->minDiscountPercent !== null
                    && $product->discountPercent() < $criteria->minDiscountPercent) {
                    continue;
                }

                $delivered++;

                yield $product;

                if ($delivered >= $criteria->maxItems) {
                    return;
                }
            }

            if (($offer['pageInfo']['hasNextPage'] ?? false) !== true) {
                return;
            }

            $page++;
        }
    }

    public function fetchProduct(string $externalId): ?ProductData
    {
        // The affiliate API exposes no single item lookup; the offer feed is the
        // only source. Returning null keeps the contract honest rather than
        // pretending with a fabricated call.
        return null;
    }

    public function buildAffiliateUrl(string $url): string
    {
        // Shopee returns a ready made offerLink; a plain product URL cannot be
        // converted client side, so it is passed through unchanged.
        return $url;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<mixed>
     */
    private function query(string $query, array $variables): array
    {
        [$appId, $secret] = $this->credentials();

        $payload = json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR);
        $timestamp = time();

        /*
         * Signature covers the payload, so a tampered body is rejected. The order
         * of concatenation is fixed by Shopee and any deviation yields a valid
         * looking but rejected signature.
         */
        $signature = hash('sha256', $appId.$timestamp.$payload.$secret);

        $response = $this->send('POST', $this->endpoint(), [
            'body' => $payload,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "SHA256 Credential={$appId}, Timestamp={$timestamp}, Signature={$signature}",
            ],
        ]);

        if ($response === null) {
            return [];
        }

        // GraphQL reports failures inside a 200 response.
        if (isset($response['errors'][0]['message'])) {
            throw new ConnectorException('Shopee rejected the query: '.(string) $response['errors'][0]['message']);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function toProductData(array $node): ?ProductData
    {
        $itemId = $node['itemId'] ?? null;
        $title = $node['productName'] ?? null;
        $price = $node['price'] ?? $node['priceMin'] ?? null;

        if (! is_scalar($itemId) || ! is_string($title) || ! is_numeric($price)) {
            return null;
        }

        $link = is_string($node['offerLink'] ?? null) && $node['offerLink'] !== ''
            ? $node['offerLink']
            : (is_string($node['productLink'] ?? null) ? $node['productLink'] : "https://shopee.com.br/product/{$itemId}");

        $current = Money::fromNumericString((string) $price);

        return new ProductData(
            externalId: (string) $itemId,
            title: $title,
            productUrl: $link,
            currentPrice: $current,
            // Shopee reports a discount rate rather than the previous price, so it
            // is reconstructed. Only the rate is available, which is exactly why
            // the scoring engine still judges against our own observed history.
            previousPrice: $this->previousPriceFrom($node, $current),
            brand: null,
            categoryExternalId: $this->firstCategory($node),
            imageUrl: is_string($node['imageUrl'] ?? null) ? $node['imageUrl'] : null,
            affiliateUrl: $link,
            rating: is_numeric($node['ratingStar'] ?? null)
                ? min(5.0, max(0.0, (float) $node['ratingStar']))
                : null,
            reviewsCount: (int) ($node['sales'] ?? 0),
            isPrime: false,
            hasFreeShipping: false,
            inStock: true,
            rawPayload: $node,
        );
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function previousPriceFrom(array $node, Money $current): ?Money
    {
        $rate = $node['priceDiscountRate'] ?? null;

        if (! is_numeric($rate) || (float) $rate <= 0 || (float) $rate >= 100) {
            return null;
        }

        return Money::fromCents((int) round($current->cents / (1 - (float) $rate / 100)));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function firstCategory(array $node): ?string
    {
        $categories = $node['productCatIds'] ?? null;

        if (! is_array($categories) || $categories === []) {
            return null;
        }

        $first = reset($categories);

        return is_scalar($first) ? (string) $first : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        $appId = $this->marketplace->credential('app_id');
        $secret = $this->marketplace->credential('app_secret');

        if ($appId === null || $secret === null) {
            throw new AuthenticationException(
                "Shopee credentials are missing for [{$this->identifier()}]; set app_id and app_secret."
            );
        }

        return [$appId, $secret];
    }

    private function endpoint(): string
    {
        $endpoint = $this->marketplace->setting('endpoint', self::DEFAULT_ENDPOINT);

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }
}
