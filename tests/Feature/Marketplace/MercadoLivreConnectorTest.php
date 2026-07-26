<?php

declare(strict_types=1);

use App\Domain\Marketplace\Exceptions\AuthenticationException;
use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Domain\Marketplace\TokenType;
use App\Infrastructure\Marketplace\Connectors\MercadoLivreConnector;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Infrastructure\Persistence\MarketplaceTokenRepository;
use App\Models\Marketplace;
use App\Models\MarketplaceToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function mlFixture(string $name): array
{
    /** @var array<mixed> */
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/MercadoLivre/{$name}.json")), true);
}

beforeEach(function () {
    $this->marketplace = Marketplace::factory()->create([
        'slug' => 'mercadolivre',
        'connector' => MercadoLivreConnector::class,
        'rate_limit_per_minute' => 600,
        'credentials' => ['client_id' => 'test-id', 'client_secret' => 'test-secret'],
        'settings' => ['site_id' => 'MLB', 'affiliate_tool_id' => '88888'],
    ]);

    $this->connector = app(MarketplaceConnectorManager::class)->for($this->marketplace);
});

it('is resolved from the connector column rather than a hardcoded mapping', function () {
    expect($this->connector)->toBeInstanceOf(MercadoLivreConnector::class)
        ->and($this->connector->identifier())->toBe('mercadolivre');
});

it('normalises a search payload into the canonical product shape', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response(mlFixture('search-page-1'))]);

    /** @var list<ProductData> $products */
    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 2)));

    $cafeteira = $products[0];

    expect($cafeteira->externalId)->toBe('MLB1234567890')
        ->and($cafeteira->currentPrice->toNumericString())->toBe('249.90')
        ->and($cafeteira->previousPrice?->toNumericString())->toBe('399.90')
        ->and($cafeteira->brand)->toBe('Mondial')
        ->and($cafeteira->rating)->toBe(4.6)
        ->and($cafeteira->reviewsCount)->toBe(812)
        ->and($cafeteira->hasFreeShipping)->toBeTrue()
        ->and($cafeteira->inStock)->toBeTrue()
        ->and($cafeteira->categoryExternalId)->toBe('MLB1051')
        // The original payload is preserved verbatim for later re-interpretation.
        ->and($cafeteira->rawPayload['sold_quantity'])->toBe(340);
});

it('computes the discount from prices rather than trusting a claimed percentage', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response(mlFixture('search-page-1'))]);

    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 2)));

    // 249.90 against 399.90 is 37.5%, floored to 37.
    expect($products[0]->discountPercent())->toBe(37)
        ->and($products[0]->savings()?->toNumericString())->toBe('150.00');
});

it('marks a product with no available quantity as out of stock', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response(mlFixture('search-page-1'))]);

    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 2)));

    expect($products[1]->inStock)->toBeFalse()
        ->and($products[1]->previousPrice)->toBeNull()
        ->and($products[1]->discountPercent())->toBe(0);
});

it('requests the full size image rather than the thumbnail', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response(mlFixture('search-page-1'))]);

    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 2)));

    expect($products[0]->imageUrl)->toBe('https://http2.mlstatic.com/D_811718-MLB-O.jpg');
});

it('appends affiliate parameters to the product url', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response(mlFixture('search-page-1'))]);

    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 2)));

    expect($products[0]->affiliateUrl)->toContain('matt_tool=88888')
        // The second product's permalink already has a query string.
        ->and($products[1]->affiliateUrl)->toContain('utm=x&matt_tool=88888');
});

it('returns the plain url when no affiliate tag is configured', function () {
    $this->marketplace->update(['settings' => ['site_id' => 'MLB']]);
    app(MarketplaceConnectorManager::class)->flush();
    $connector = app(MarketplaceConnectorManager::class)->for($this->marketplace->fresh());

    expect($connector->buildAffiliateUrl('https://example.test/p/1'))->toBe('https://example.test/p/1');
});

it('skips catalogue entries that cannot be priced instead of failing the page', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response([
        'paging' => ['total' => 2],
        'results' => [mlFixture('item-unpriceable'), mlFixture('search-page-1')['results'][0]],
    ])]);

    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 5)));

    expect($products)->toHaveCount(1)
        ->and($products[0]->externalId)->toBe('MLB1234567890');
});

it('pages lazily, fetching the next page only when the consumer asks for it', function () {
    $requests = 0;

    Http::fake(function (Request $request) use (&$requests) {
        $requests++;

        return Http::response(str_contains($request->url(), 'offset=2')
            ? mlFixture('search-page-2')
            : mlFixture('search-page-1'));
    });

    $deals = $this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 10));

    expect($deals)->toBeInstanceOf(Generator::class)
        // Nothing is requested until the generator is advanced.
        ->and($requests)->toBe(0);

    $deals->current();
    expect($requests)->toBe(1);

    // Both products of page one are consumed before page two is requested.
    $deals->next();
    expect($requests)->toBe(1);

    $deals->next();
    expect($requests)->toBe(2)
        ->and($deals->current()->externalId)->toBe('MLB5555555555');
});

it('stops paging as soon as the item budget is spent', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response(mlFixture('search-page-1'))]);

    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 1)));

    expect($products)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('filters below the requested minimum discount', function () {
    Http::fake(['*/sites/MLB/search*' => Http::response(mlFixture('search-page-1'))]);

    $products = iterator_to_array(
        $this->connector->fetchDeals((new FetchCriteria(pageSize: 2, maxItems: 5))->withMinDiscount(50))
    );

    // 37% does not clear a 50% bar, and the second product has no discount at all.
    expect($products)->toBeEmpty();
});

it('stores the access and refresh tokens returned by the oauth exchange', function () {
    Http::fake(['*/oauth/token' => Http::response(mlFixture('oauth-token'))]);

    $this->connector->authenticate();

    $access = (new MarketplaceTokenRepository)->current($this->marketplace, TokenType::Access);
    $refresh = (new MarketplaceTokenRepository)->current($this->marketplace, TokenType::Refresh);

    expect($access?->value)->toBe('APP_USR-1234567890abcdef')
        ->and($access?->expires_at?->isFuture())->toBeTrue()
        ->and($refresh?->value)->toBe('TG-refresh-abcdef123456');
});

it('sends the stored access token on subsequent calls', function () {
    Http::fake([
        '*/oauth/token' => Http::response(mlFixture('oauth-token')),
        '*/sites/MLB/search*' => Http::response(mlFixture('search-page-1')),
    ]);

    $this->connector->authenticate();
    iterator_to_array($this->connector->fetchDeals(new FetchCriteria(pageSize: 2, maxItems: 1)));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search')
        && $request->hasHeader('Authorization', 'Bearer APP_USR-1234567890abcdef'));
});

it('retires the previous token when a new one is issued', function () {
    Http::fake(['*/oauth/token' => Http::response(mlFixture('oauth-token'))]);

    $this->connector->authenticate();
    $this->connector->authenticate();

    $all = MarketplaceToken::query()->where('type', TokenType::Access)->get();

    expect($all)->toHaveCount(2)
        // Exactly one current token; the partial unique index enforces it, and the
        // rotated row survives for auditing which credential a failure used.
        ->and($all->whereNull('rotated_at'))->toHaveCount(1);
});

it('refuses to authenticate without credentials', function () {
    $this->marketplace->update(['credentials' => null]);
    app(MarketplaceConnectorManager::class)->flush();

    app(MarketplaceConnectorManager::class)->for($this->marketplace->fresh())->authenticate();
})->throws(AuthenticationException::class);

it('treats a delisted product as absent rather than as a failure', function () {
    Http::fake(['*/items/*' => Http::response(['message' => 'not found'], 404)]);

    expect($this->connector->fetchProduct('MLB404'))->toBeNull()
        // The marketplace answered correctly, so nothing counts against the breaker.
        ->and($this->marketplace->fresh()->consecutive_failures)->toBe(0);
});
