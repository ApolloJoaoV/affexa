<?php

declare(strict_types=1);

use App\Domain\Marketplace\Exceptions\AuthenticationException;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Domain\Marketplace\FetchCriteria;
use App\Infrastructure\Marketplace\Connectors\AmazonConnector;
use App\Infrastructure\Marketplace\Connectors\ShopeeConnector;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Models\Marketplace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function connectorFixture(string $path): array
{
    /** @var array<mixed> */
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/{$path}.json")), true);
}

function amazonMarketplace(): Marketplace
{
    return Marketplace::factory()->create([
        'slug' => 'amazon-'.uniqid(),
        'connector' => AmazonConnector::class,
        'rate_limit_per_minute' => 600,
        'credentials' => ['access_key' => 'AKIDEXAMPLE', 'secret_key' => 'secret'],
        'settings' => ['partner_tag' => 'promohub-20'],
    ]);
}

function shopeeMarketplace(): Marketplace
{
    return Marketplace::factory()->create([
        'slug' => 'shopee-'.uniqid(),
        'connector' => ShopeeConnector::class,
        'rate_limit_per_minute' => 600,
        'credentials' => ['app_id' => '18000000', 'app_secret' => 'shopee-secret'],
    ]);
}

it('signs every amazon request with sigv4', function () {
    Http::fake(['*' => Http::response(connectorFixture('Amazon/search-items'))]);

    $connector = app(MarketplaceConnectorManager::class)->for(amazonMarketplace());
    iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1)));

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('x-amz-date')
            && $request->hasHeader('x-amz-target', 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems')
            && str_starts_with((string) $request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/');
    });
});

it('normalises an amazon search payload', function () {
    // Only page one has results, as the live API behaves once the catalogue is
    // exhausted; a fake that answers every page would loop to the item budget.
    Http::fake(fn (Request $request) => Http::response(
        str_contains($request->body(), '"ItemPage":1')
            ? connectorFixture('Amazon/search-items')
            : ['SearchResult' => ['Items' => []]]
    ));

    $connector = app(MarketplaceConnectorManager::class)->for(amazonMarketplace());
    $products = iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 5)));

    expect($products)->toHaveCount(1);

    $fone = $products[0];

    expect($fone->externalId)->toBe('B08XYZ1234')
        ->and($fone->currentPrice->toNumericString())->toBe('349.00')
        ->and($fone->previousPrice?->toNumericString())->toBe('599.00')
        ->and($fone->discountPercent())->toBe(41)
        ->and($fone->brand)->toBe('JBL')
        ->and($fone->isPrime)->toBeTrue()
        ->and($fone->hasFreeShipping)->toBeTrue()
        ->and($fone->rating)->toBe(4.5)
        ->and($fone->reviewsCount)->toBe(2410)
        ->and($fone->categoryExternalId)->toBe('16209062011');
});

it('skips an amazon item that has no buyable offer', function () {
    Http::fake(['*' => Http::response(connectorFixture('Amazon/search-items'))]);

    $connector = app(MarketplaceConnectorManager::class)->for(amazonMarketplace());
    $products = iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 5)));

    // Nothing to price means nothing to promote; the page still yields its
    // usable item rather than failing.
    expect(collect($products)->pluck('externalId'))->not->toContain('B09NOOFFER');
});

it('surfaces an amazon business error carried in a successful response', function () {
    Http::fake(['*' => Http::response(connectorFixture('Amazon/error'))]);

    $connector = app(MarketplaceConnectorManager::class)->for(amazonMarketplace());

    iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1)));
})->throws(ConnectorException::class, 'InvalidPartnerTag');

it('never asks amazon for more than the api allows per page', function () {
    Http::fake(['*' => Http::response(connectorFixture('Amazon/search-items'))]);

    $connector = app(MarketplaceConnectorManager::class)->for(amazonMarketplace());
    iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 100, pageSize: 50)));

    Http::assertSent(function (Request $request): bool {
        /** @var array{ItemCount: int} $body */
        $body = json_decode($request->body(), true);

        // PA-API rejects an ItemCount above ten outright.
        return $body['ItemCount'] === 10;
    });
});

it('adds the affiliate tag only when it is absent', function () {
    $connector = app(MarketplaceConnectorManager::class)->for(amazonMarketplace());

    expect($connector->buildAffiliateUrl('https://www.amazon.com.br/dp/B01'))
        ->toBe('https://www.amazon.com.br/dp/B01?tag=promohub-20')
        ->and($connector->buildAffiliateUrl('https://www.amazon.com.br/dp/B01?tag=other'))
        ->toBe('https://www.amazon.com.br/dp/B01?tag=other');
});

it('refuses to build an amazon signer without credentials', function () {
    $marketplace = Marketplace::factory()->create([
        'connector' => AmazonConnector::class,
        'credentials' => null,
    ]);

    app(MarketplaceConnectorManager::class)->for($marketplace)->authenticate();
})->throws(AuthenticationException::class, 'access_key');

it('signs shopee requests over the payload', function () {
    Http::fake(['*' => Http::response(connectorFixture('Shopee/product-offers'))]);

    $marketplace = shopeeMarketplace();
    $connector = app(MarketplaceConnectorManager::class)->for($marketplace);
    iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1)));

    Http::assertSent(function (Request $request): bool {
        $authorization = (string) $request->header('Authorization')[0];

        if (! str_starts_with($authorization, 'SHA256 Credential=18000000,')) {
            return false;
        }

        preg_match('/Timestamp=(\d+), Signature=([a-f0-9]{64})/', $authorization, $matches);

        // Recomputing the signature proves the payload is what was signed.
        $expected = hash('sha256', '18000000'.$matches[1].$request->body().'shopee-secret');

        return $matches[2] === $expected;
    });
});

it('normalises a shopee offer payload', function () {
    Http::fake(['*' => Http::response(connectorFixture('Shopee/product-offers'))]);

    $connector = app(MarketplaceConnectorManager::class)->for(shopeeMarketplace());
    $products = iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 5)));

    expect($products)->toHaveCount(1);

    $panela = $products[0];

    expect($panela->externalId)->toBe('22334455')
        ->and($panela->currentPrice->toNumericString())->toBe('199.90')
        // Shopee reports a rate, not a previous price, so it is reconstructed:
        // 199.90 at 50% off implies 399.80.
        ->and($panela->previousPrice?->toNumericString())->toBe('399.80')
        ->and($panela->discountPercent())->toBe(50)
        ->and($panela->rating)->toBe(4.8)
        ->and($panela->reviewsCount)->toBe(1520)
        ->and($panela->affiliateUrl)->toBe('https://s.shopee.com.br/AbCdEf')
        ->and($panela->categoryExternalId)->toBe('11029');
});

it('stops shopee paging when the api reports no further pages', function () {
    Http::fake(['*' => Http::response(connectorFixture('Shopee/product-offers'))]);

    $connector = app(MarketplaceConnectorManager::class)->for(shopeeMarketplace());
    iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 500)));

    // hasNextPage is false, so one request is all it may make.
    Http::assertSentCount(1);
});

it('surfaces a shopee graphql error', function () {
    Http::fake(['*' => Http::response(['errors' => [['message' => 'invalid signature']]])]);

    $connector = app(MarketplaceConnectorManager::class)->for(shopeeMarketplace());

    iterator_to_array($connector->fetchDeals(new FetchCriteria(maxItems: 1)));
})->throws(ConnectorException::class, 'invalid signature');

it('reports honestly that shopee has no single item lookup', function () {
    $connector = app(MarketplaceConnectorManager::class)->for(shopeeMarketplace());

    expect($connector->fetchProduct('22334455'))->toBeNull();
    Http::assertNothingSent();
});
