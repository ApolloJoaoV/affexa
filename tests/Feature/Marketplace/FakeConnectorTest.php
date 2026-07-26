<?php

declare(strict_types=1);

use App\Domain\Marketplace\Contracts\MarketplaceConnector;
use App\Domain\Marketplace\Exceptions\ConnectorException;
use App\Domain\Marketplace\FetchCriteria;
use App\Domain\Marketplace\ProductData;
use App\Domain\Pricing\Money;
use App\Infrastructure\Marketplace\Connectors\FakeConnector;
use App\Infrastructure\Marketplace\MarketplaceConnectorManager;
use App\Models\Marketplace;

beforeEach(function () {
    $this->marketplace = Marketplace::factory()->create([
        'slug' => 'fake-shop',
        'connector' => FakeConnector::class,
    ]);

    $this->connector = app(MarketplaceConnectorManager::class)->for($this->marketplace);
});

it('is resolvable through the manager like any other connector', function () {
    expect($this->connector)->toBeInstanceOf(FakeConnector::class)
        ->and($this->connector)->toBeInstanceOf(MarketplaceConnector::class)
        ->and($this->connector->identifier())->toBe('fake-shop');
});

it('yields products lazily rather than building the catalogue', function () {
    $deals = $this->connector->fetchDeals(new FetchCriteria(maxItems: 1000));

    expect($deals)->toBeInstanceOf(Generator::class);

    // Take three from a catalogue of hundreds.
    $taken = [];
    foreach ($deals as $product) {
        $taken[] = $product;

        if (count($taken) === 3) {
            break;
        }
    }

    expect($taken)->toHaveCount(3)
        // Only three were ever produced; a marketplace with tens of thousands of
        // items must not be materialised to read the first few.
        ->and($this->connector->yieldedCount())->toBe(3);
});

it('honours the item budget', function () {
    $products = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(maxItems: 12)));

    expect($products)->toHaveCount(12);
});

it('is deterministic for a given index', function () {
    $first = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(maxItems: 5)));
    $second = iterator_to_array($this->connector->fetchDeals(new FetchCriteria(maxItems: 5)));

    expect($first[4]->externalId)->toBe($second[4]->externalId)
        ->and($first[4]->currentPrice->cents)->toBe($second[4]->currentPrice->cents);
});

it('filters by minimum discount', function () {
    $products = iterator_to_array(
        $this->connector->fetchDeals((new FetchCriteria(maxItems: 40))->withMinDiscount(40))
    );

    expect($products)->not->toBeEmpty();

    foreach ($products as $product) {
        expect($product->discountPercent())->toBeGreaterThanOrEqual(40);
    }
});

it('can be scripted with exact products for a test', function () {
    /** @var FakeConnector $connector */
    $connector = $this->connector;

    $connector->script([
        new ProductData(
            externalId: 'SCRIPTED-1',
            title: 'Produto Combinado',
            productUrl: 'https://fake.test/1',
            currentPrice: Money::fromNumericString('50.00'),
            previousPrice: Money::fromNumericString('100.00'),
        ),
    ]);

    $products = iterator_to_array($connector->fetchDeals(new FetchCriteria));

    expect($products)->toHaveCount(1)
        ->and($products[0]->externalId)->toBe('SCRIPTED-1')
        ->and($products[0]->discountPercent())->toBe(50);
});

it('returns null for an unknown product', function () {
    expect($this->connector->fetchProduct('NOT-A-FAKE-ID'))->toBeNull()
        ->and($this->connector->fetchProduct('FAKE-7'))->not->toBeNull();
});

it('rejects a marketplace configured with a class that is not a connector', function () {
    $broken = Marketplace::factory()->create(['connector' => Marketplace::class]);

    app(MarketplaceConnectorManager::class)->for($broken);
})->throws(ConnectorException::class, 'does not implement');

it('rejects a marketplace configured with a missing class', function () {
    $broken = Marketplace::factory()->create(['connector' => 'App\\Nope\\NoSuchConnector']);

    app(MarketplaceConnectorManager::class)->for($broken);
})->throws(ConnectorException::class, 'does not exist');
