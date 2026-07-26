<?php

declare(strict_types=1);

use App\Domain\Pricing\Money;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('computes discount_percent in the database', function () {
    $product = Product::factory()->pricedAt('75.00', '100.00')->create();

    expect($product->refresh()->discount_percent)->toBe(25);
});

it('recomputes discount_percent when a price changes', function () {
    $product = Product::factory()->pricedAt('100.00', '100.00')->create();
    expect($product->refresh()->discount_percent)->toBe(0);

    $product->update(['current_price' => Money::fromNumericString('40.00')]);

    expect($product->refresh()->discount_percent)->toBe(60);
});

it('treats a missing or zero reference price as no discount', function (?string $previous) {
    $product = Product::factory()->pricedAt('50.00', $previous)->create();

    expect($product->refresh()->discount_percent)->toBe(0);
})->with([null, '0.00']);

it('floors fractional discounts rather than rounding up', function () {
    // 100 -> 66.67 is 33.33%, which must not be advertised as 34%.
    $product = Product::factory()->pricedAt('66.67', '100.00')->create();

    expect($product->refresh()->discount_percent)->toBe(33);
});

it('refuses a discount_percent written by the application', function () {
    $product = Product::factory()->pricedAt('75.00', '100.00')->create();

    // The whole point of generating the column: no code path can make the stored
    // discount disagree with the stored prices.
    DB::update('UPDATE products SET discount_percent = 90 WHERE id = ?', [$product->id]);
})->throws(QueryException::class);

it('maintains the search vector from the title and brand', function () {
    $product = Product::factory()->titled('Cafeteira Elétrica Inox')->create(['brand' => 'Mondial']);

    $matches = DB::scalar(
        "SELECT search_vector @@ websearch_to_tsquery('public.pt_unaccent', ?) FROM products WHERE id = ?",
        ['cafeteira eletrica', $product->id]
    );

    expect($matches)->toBeTrue();
});

it('updates the search vector when the title changes', function () {
    $product = Product::factory()->titled('Liquidificador Turbo')->create();

    $product->update(['title' => 'Batedeira Planetária']);

    $matches = DB::scalar(
        "SELECT search_vector @@ websearch_to_tsquery('public.pt_unaccent', ?) FROM products WHERE id = ?",
        ['batedeira', $product->id],
    );

    expect($matches)->toBeTrue();
});

it('reads money columns back as exact value objects', function () {
    $product = Product::factory()->pricedAt('1299.90', '1899.99')->create();

    $fresh = Product::findOrFail($product->id);

    expect($fresh->current_price)->toBeInstanceOf(Money::class)
        ->and($fresh->current_price?->cents)->toBe(129990)
        ->and($fresh->previous_price?->cents)->toBe(189999);
});
