<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace\Connectors;

use App\Domain\Marketplace\Exceptions\AuthenticationException;
use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Domain\Marketplace\TokenType;
use App\Domain\Pricing\Money;
use App\Infrastructure\Marketplace\AbstractMarketplaceConnector;
use App\Infrastructure\Marketplace\ApiCallLogger;
use App\Infrastructure\Marketplace\CircuitBreaker;
use App\Infrastructure\Marketplace\TokenBucketRateLimiter;
use App\Infrastructure\Persistence\MarketplaceTokenRepository;
use App\Models\Marketplace;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Client\PendingRequest;

/**
 * Mercado Livre.
 *
 * Authentication is OAuth2 client credentials; searches run against the site
 * catalogue with paging by offset.
 *
 * Field mapping follows Mercado Livre's documented response shape and is covered
 * by fixture based tests. It has not been exercised against the live API, which
 * needs real credentials — treat the first run against production as the actual
 * verification of these mappings.
 */
final class MercadoLivreConnector extends AbstractMarketplaceConnector
{
    private const API = 'https://api.mercadolibre.com';

    /**
     * Brazil. Configurable because the same connector serves other countries.
     */
    private const DEFAULT_SITE = 'MLB';

    public function __construct(
        Marketplace $marketplace,
        TokenBucketRateLimiter $rateLimiter,
        CircuitBreaker $circuitBreaker,
        ApiCallLogger $callLogger,
        private readonly MarketplaceTokenRepository $tokens,
    ) {
        parent::__construct($marketplace, $rateLimiter, $circuitBreaker, $callLogger);
    }

    public function authenticate(): void
    {
        $clientId = $this->marketplace->credential('client_id');
        $clientSecret = $this->marketplace->credential('client_secret');

        if ($clientId === null || $clientSecret === null) {
            throw new AuthenticationException(
                "Mercado Livre credentials are missing for [{$this->identifier()}]; set client_id and client_secret."
            );
        }

        $this->storeToken($this->post(self::API.'/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ], asForm: true));
    }

    /**
     * Renews using the refresh token when one was issued, falling back to a fresh
     * client credentials exchange otherwise.
     */
    public function refreshToken(): void
    {
        $refresh = $this->tokens->current($this->marketplace, TokenType::Refresh);

        if ($refresh === null) {
            $this->authenticate();

            return;
        }

        $clientId = $this->marketplace->credential('client_id');
        $clientSecret = $this->marketplace->credential('client_secret');

        if ($clientId === null || $clientSecret === null) {
            throw new AuthenticationException("Mercado Livre credentials are missing for [{$this->identifier()}].");
        }

        $this->storeToken($this->post(self::API.'/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh->value,
        ], asForm: true));
    }

    /**
     * Pages the catalogue lazily.
     *
     * Each page is fetched only when the consumer asks for the product after the
     * last one of the previous page, so a caller that stops at fifty products
     * never issues the sixth request.
     *
     * @return Generator<int, ProductData>
     */
    public function fetchDeals(FetchCriteria $criteria): Generator
    {
        $categories = $criteria->categoryExternalIds === [] ? [null] : $criteria->categoryExternalIds;
        $delivered = 0;

        foreach ($categories as $category) {
            $offset = 0;

            while ($delivered < $criteria->maxItems) {
                $query = array_filter([
                    'category' => $category,
                    'limit' => min($criteria->pageSize, $criteria->maxItems - $delivered),
                    'offset' => $offset,
                ], fn (mixed $value): bool => $value !== null);

                /** @var array{results?: list<array<string, mixed>>, paging?: array{total?: int}} $page */
                $page = $this->get(self::API.'/sites/'.$this->site().'/search', $query);
                $results = $page['results'] ?? [];

                if ($results === []) {
                    break;
                }

                foreach ($results as $item) {
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

                $offset += count($results);

                if ($offset >= (int) ($page['paging']['total'] ?? 0)) {
                    break;
                }
            }
        }
    }

    public function fetchProduct(string $externalId): ?ProductData
    {
        /** @var array<string, mixed>|null $item */
        $item = $this->getTolerating(self::API.'/items/'.$externalId);

        return $item === null ? null : $this->toProductData($item);
    }

    /**
     * Appends the configured affiliate parameters.
     *
     * Mercado Livre attributes a sale through query parameters on the permalink, so
     * an unconfigured marketplace simply returns the original URL rather than a
     * broken one.
     */
    public function buildAffiliateUrl(string $url): string
    {
        $tool = $this->marketplace->setting('affiliate_tool_id');
        $word = $this->marketplace->setting('affiliate_word');

        if (! is_string($tool) || $tool === '') {
            return $url;
        }

        $parameters = array_filter([
            'matt_tool' => $tool,
            'matt_word' => is_string($word) && $word !== '' ? $word : null,
        ]);

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($parameters);
    }

    protected function defaultHeaders(): array
    {
        $headers = parent::defaultHeaders();

        $token = $this->tokens->current($this->marketplace, TokenType::Access);

        if ($token !== null && ! $token->isExpired()) {
            $headers['Authorization'] = 'Bearer '.$token->value;
        }

        return $headers;
    }

    protected function request(): PendingRequest
    {
        return parent::request()->asJson();
    }

    /**
     * Turns one search or item payload into the canonical shape.
     *
     * Returns null for entries that cannot be priced, which happens for
     * catalogue placeholders; skipping is correct, failing the whole page is not.
     *
     * @param  array<string, mixed>  $item
     */
    private function toProductData(array $item): ?ProductData
    {
        $externalId = $item['id'] ?? null;
        $title = $item['title'] ?? null;
        $price = $item['price'] ?? null;

        if (! is_string($externalId) || ! is_string($title) || ! is_numeric($price)) {
            return null;
        }

        $original = $item['original_price'] ?? null;
        $permalink = is_string($item['permalink'] ?? null) ? $item['permalink'] : self::API.'/items/'.$externalId;

        /** @var array<string, mixed> $shipping */
        $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];

        return new ProductData(
            externalId: $externalId,
            title: $title,
            productUrl: $permalink,
            currentPrice: Money::fromNumericString((string) $price),
            // original_price is Mercado Livre's own "was" figure. It is recorded as
            // the previous price but the scoring engine still judges against our
            // observed history, never against this number alone.
            previousPrice: is_numeric($original) ? Money::fromNumericString((string) $original) : null,
            listPrice: is_numeric($original) ? Money::fromNumericString((string) $original) : null,
            brand: $this->attribute($item, 'BRAND'),
            variation: is_scalar($item['variation_id'] ?? null) ? (string) $item['variation_id'] : null,
            categoryExternalId: is_string($item['category_id'] ?? null) ? $item['category_id'] : null,
            imageUrl: $this->imageUrl($item),
            affiliateUrl: $this->buildAffiliateUrl($permalink),
            rating: $this->rating($item),
            reviewsCount: (int) ($this->reviews($item)['total'] ?? 0),
            isPrime: false,
            hasFreeShipping: ($shipping['free_shipping'] ?? false) === true,
            inStock: (int) ($item['available_quantity'] ?? 0) > 0,
            rawPayload: $item,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function imageUrl(array $item): ?string
    {
        foreach (['thumbnail', 'secure_thumbnail'] as $key) {
            if (is_string($item[$key] ?? null) && $item[$key] !== '') {
                // The thumbnail is a small variant; the -O suffix asks for the
                // original, which is what the card generator needs.
                return str_replace('-I.jpg', '-O.jpg', $item[$key]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{total?: int, rating_average?: float}
     */
    private function reviews(array $item): array
    {
        /** @var array{total?: int, rating_average?: float} */
        return is_array($item['reviews'] ?? null) ? $item['reviews'] : [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function rating(array $item): ?float
    {
        $average = $this->reviews($item)['rating_average'] ?? null;

        // Already a 0 to 5 scale on this marketplace, but clamped rather than
        // trusted: ProductData rejects anything outside the range.
        return is_numeric($average) ? min(5.0, max(0.0, (float) $average)) : null;
    }

    /**
     * Reads a value out of the attributes array, which is a list of id/value pairs.
     *
     * @param  array<string, mixed>  $item
     */
    private function attribute(array $item, string $id): ?string
    {
        $attributes = $item['attributes'] ?? null;

        if (! is_array($attributes)) {
            return null;
        }

        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ($attribute['id'] ?? null) !== $id) {
                continue;
            }

            $value = $attribute['value_name'] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function storeToken(array $payload): void
    {
        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new AuthenticationException("Mercado Livre returned no access token for [{$this->identifier()}].");
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 0);

        $this->tokens->store(
            $this->marketplace,
            TokenType::Access,
            $accessToken,
            $expiresIn > 0 ? CarbonImmutable::now()->addSeconds($expiresIn) : null,
            ['scope' => $payload['scope'] ?? null],
        );

        if (is_string($payload['refresh_token'] ?? null)) {
            $this->tokens->store($this->marketplace, TokenType::Refresh, $payload['refresh_token']);
        }
    }

    private function site(): string
    {
        $site = $this->marketplace->setting('site_id', self::DEFAULT_SITE);

        return is_string($site) && $site !== '' ? $site : self::DEFAULT_SITE;
    }
}
