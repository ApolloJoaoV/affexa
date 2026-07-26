<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace\Connectors;

use App\Domain\Marketplace\Exceptions\AuthenticationException;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Domain\Pricing\Money;
use App\Infrastructure\Marketplace\AbstractMarketplaceConnector;
use App\Infrastructure\Marketplace\Signing\AwsSignatureV4;
use Generator;

/**
 * Amazon, through the Product Advertising API v5.
 *
 * Unlike the other connectors there is no token to store: every request is signed
 * with AWS Signature V4 from the stored key pair, so authenticate() has nothing to
 * exchange.
 *
 * Field mapping follows the documented PA-API response shape and is covered by
 * fixtures. It has not been run against the live API, which needs approved
 * Associates credentials.
 */
final class AmazonConnector extends AbstractMarketplaceConnector
{
    private const SERVICE = 'ProductAdvertisingAPI';

    private const TARGET_PREFIX = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.';

    /**
     * PA-API caps a search at ten items per page and ten pages. Asking for more is
     * an error, not a larger result, so the ceiling is enforced here.
     */
    private const MAX_PAGE_SIZE = 10;

    private const MAX_PAGES = 10;

    /**
     * @var list<string>
     */
    private const RESOURCES = [
        'ItemInfo.Title',
        'ItemInfo.ByLineInfo',
        'ItemInfo.Classifications',
        'Images.Primary.Large',
        'Offers.Listings.Price',
        'Offers.Listings.SavingBasis',
        'Offers.Listings.DeliveryInfo.IsPrimeEligible',
        'Offers.Listings.DeliveryInfo.IsFreeShippingEligible',
        'Offers.Listings.Availability.Type',
        'BrowseNodeInfo.BrowseNodes',
    ];

    /**
     * Signing keys are per request, so there is nothing to authenticate ahead of
     * time. Credentials are validated here so a misconfiguration surfaces at setup
     * rather than as an opaque 403 during a fetch run.
     */
    public function authenticate(): void
    {
        $this->signer();
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
        $searchIndexes = $criteria->categoryExternalIds === [] ? ['All'] : $criteria->categoryExternalIds;
        $delivered = 0;
        $pageSize = min($criteria->pageSize, self::MAX_PAGE_SIZE);

        foreach ($searchIndexes as $searchIndex) {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                if ($delivered >= $criteria->maxItems) {
                    return;
                }

                /** @var array{SearchResult?: array{Items?: list<array<string, mixed>>}} $response */
                $response = $this->call('SearchItems', array_filter([
                    'Keywords' => $this->keywords(),
                    'SearchIndex' => $searchIndex,
                    'ItemCount' => $pageSize,
                    'ItemPage' => $page,
                    'MinSavingPercent' => $criteria->minDiscountPercent,
                ], fn (mixed $value): bool => $value !== null && $value !== ''));

                $items = $response['SearchResult']['Items'] ?? [];

                if ($items === []) {
                    break;
                }

                foreach ($items as $item) {
                    $product = $this->toProductData($item);

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
            }
        }
    }

    public function fetchProduct(string $externalId): ?ProductData
    {
        /** @var array{ItemsResult?: array{Items?: list<array<string, mixed>>}} $response */
        $response = $this->call('GetItems', ['ItemIds' => [$externalId]], tolerateMissing: true);

        $item = $response['ItemsResult']['Items'][0] ?? null;

        return is_array($item) ? $this->toProductData($item) : null;
    }

    /**
     * Amazon attributes through the tag query parameter. DetailPageURL already
     * carries it when PartnerTag was sent, so this only fills the gap for URLs
     * from elsewhere.
     */
    public function buildAffiliateUrl(string $url): string
    {
        $tag = $this->partnerTag();

        if ($tag === null || str_contains($url, 'tag=')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'tag='.urlencode($tag);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    private function call(string $operation, array $payload, bool $tolerateMissing = false): array
    {
        $body = json_encode($payload + [
            'PartnerTag' => $this->partnerTag(),
            'PartnerType' => 'Associates',
            'Marketplace' => $this->marketplaceDomain(),
            'Resources' => self::RESOURCES,
        ], JSON_THROW_ON_ERROR);

        $path = '/paapi5/'.strtolower($operation);

        $headers = $this->signer()->sign(
            method: 'POST',
            host: $this->host(),
            path: $path,
            payload: $body,
            headers: [
                'content-encoding' => 'amz-1.0',
                'content-type' => 'application/json; charset=utf-8',
                'x-amz-target' => self::TARGET_PREFIX.$operation,
            ],
        );

        $response = $this->send(
            'POST',
            'https://'.$this->host().$path,
            ['body' => $body, 'headers' => $headers],
            $tolerateMissing ? [404] : [],
        );

        if ($response === null) {
            return [];
        }

        // PA-API reports business errors in the body of an otherwise valid response.
        if (isset($response['Errors'][0])) {
            /** @var array{Code?: string, Message?: string} $error */
            $error = $response['Errors'][0];

            // The code is what an operator acts on — InvalidPartnerTag and
            // TooManyRequests need entirely different responses — so it leads.
            throw new ConnectorException(sprintf(
                'Amazon rejected %s [%s]: %s',
                $operation,
                $error['Code'] ?? 'Unknown',
                $error['Message'] ?? 'no message supplied',
            ));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toProductData(array $item): ?ProductData
    {
        $asin = $item['ASIN'] ?? null;
        $title = $item['ItemInfo']['Title']['DisplayValue'] ?? null;

        /** @var array<string, mixed>|null $listing */
        $listing = $item['Offers']['Listings'][0] ?? null;
        $amount = $listing['Price']['Amount'] ?? null;

        if (! is_string($asin) || ! is_string($title) || ! is_numeric($amount)) {
            // No buyable offer: nothing to price, so nothing to promote.
            return null;
        }

        $savingBasis = $listing['SavingBasis']['Amount'] ?? null;
        $detailPage = is_string($item['DetailPageURL'] ?? null)
            ? $item['DetailPageURL']
            : "https://{$this->marketplaceDomain()}/dp/{$asin}";

        $rating = $item['CustomerReviews']['StarRating']['Value'] ?? null;

        return new ProductData(
            externalId: $asin,
            title: $title,
            productUrl: $detailPage,
            currentPrice: Money::fromNumericString((string) $amount),
            previousPrice: is_numeric($savingBasis) ? Money::fromNumericString((string) $savingBasis) : null,
            listPrice: is_numeric($savingBasis) ? Money::fromNumericString((string) $savingBasis) : null,
            brand: is_string($item['ItemInfo']['ByLineInfo']['Brand']['DisplayValue'] ?? null)
                ? $item['ItemInfo']['ByLineInfo']['Brand']['DisplayValue']
                : null,
            categoryExternalId: $this->browseNodeId($item),
            imageUrl: is_string($item['Images']['Primary']['Large']['URL'] ?? null)
                ? $item['Images']['Primary']['Large']['URL']
                : null,
            affiliateUrl: $this->buildAffiliateUrl($detailPage),
            rating: is_numeric($rating) ? min(5.0, max(0.0, (float) $rating)) : null,
            reviewsCount: (int) ($item['CustomerReviews']['Count'] ?? 0),
            isPrime: ($listing['DeliveryInfo']['IsPrimeEligible'] ?? false) === true,
            hasFreeShipping: ($listing['DeliveryInfo']['IsFreeShippingEligible'] ?? false) === true,
            inStock: ($listing['Availability']['Type'] ?? 'Now') === 'Now',
            rawPayload: $item,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function browseNodeId(array $item): ?string
    {
        $node = $item['BrowseNodeInfo']['BrowseNodes'][0]['Id'] ?? null;

        return is_scalar($node) ? (string) $node : null;
    }

    private function signer(): AwsSignatureV4
    {
        $accessKey = $this->marketplace->credential('access_key');
        $secretKey = $this->marketplace->credential('secret_key');

        if ($accessKey === null || $secretKey === null) {
            throw new AuthenticationException(
                "Amazon credentials are missing for [{$this->identifier()}]; set access_key and secret_key."
            );
        }

        return new AwsSignatureV4($accessKey, $secretKey, $this->region(), self::SERVICE);
    }

    private function partnerTag(): ?string
    {
        $tag = $this->marketplace->setting('partner_tag');

        return is_string($tag) && $tag !== '' ? $tag : null;
    }

    private function host(): string
    {
        $host = $this->marketplace->setting('host', 'webservices.amazon.com.br');

        return is_string($host) && $host !== '' ? $host : 'webservices.amazon.com.br';
    }

    private function region(): string
    {
        // Brazil is served from us-east-1; other locales differ, hence the setting.
        $region = $this->marketplace->setting('region', 'us-east-1');

        return is_string($region) && $region !== '' ? $region : 'us-east-1';
    }

    private function marketplaceDomain(): string
    {
        $domain = $this->marketplace->setting('marketplace_domain', 'www.amazon.com.br');

        return is_string($domain) && $domain !== '' ? $domain : 'www.amazon.com.br';
    }

    private function keywords(): string
    {
        $keywords = $this->marketplace->setting('search_keywords', 'oferta');

        return is_string($keywords) && $keywords !== '' ? $keywords : 'oferta';
    }
}
