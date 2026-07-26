<?php

declare(strict_types=1);

use App\Domain\Marketplace\TokenType;
use App\Domain\Pricing\PriceSource;
use App\Domain\Promotion\PromotionConfidence;
use App\Domain\Promotion\PromotionStatus;
use App\Domain\Promotion\RejectionReason;
use App\Domain\Publishing\Channel;
use App\Domain\Publishing\PublicationStatus;
use App\Models\Marketplace;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * State columns are varchar with a CHECK constraint rather than a native
 * PostgreSQL enum, because adding a value to a native enum needs ALTER TYPE,
 * which complicates deploys and rollbacks. The trade-off is that the PHP enum and
 * the constraint can drift apart, so the two are compared here.
 */

/**
 * @return list<string>
 */
function checkConstraintValues(string $constraint): array
{
    /** @var string|null $definition */
    $definition = DB::scalar(
        'SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?',
        [$constraint]
    );

    expect($definition)->not->toBeNull("Constraint [{$constraint}] does not exist.");

    preg_match_all("/'([^']+)'/", (string) $definition, $matches);

    $values = $matches[1];
    sort($values);

    return array_values(array_unique($values));
}

/**
 * @param  list<string>  $values
 * @return list<string>
 */
function sorted(array $values): array
{
    sort($values);

    return $values;
}

it('keeps the promotion status constraint aligned with PromotionStatus', function () {
    expect(checkConstraintValues('chk_promotions_status'))->toBe(sorted(PromotionStatus::values()));
});

it('keeps the promotion confidence constraint aligned with PromotionConfidence', function () {
    expect(checkConstraintValues('chk_promotions_confidence'))->toBe(sorted(PromotionConfidence::values()));
});

it('keeps the rejection reason constraint aligned with RejectionReason', function () {
    expect(checkConstraintValues('chk_promotions_rejection_reason'))->toBe(sorted(RejectionReason::values()));
});

it('keeps the publication status constraint aligned with PublicationStatus', function () {
    expect(checkConstraintValues('chk_publications_status'))->toBe(sorted(PublicationStatus::values()));
});

it('keeps the publication channel constraints aligned with Channel', function () {
    expect(checkConstraintValues('chk_publications_channel'))->toBe(sorted(Channel::values()))
        ->and(checkConstraintValues('chk_publication_windows_channel'))->toBe(sorted(Channel::values()));
});

it('keeps the price source constraint aligned with PriceSource', function () {
    expect(checkConstraintValues('chk_price_history_source'))->toBe(sorted(PriceSource::values()));
});

it('keeps the token type constraint aligned with TokenType', function () {
    expect(checkConstraintValues('chk_marketplace_tokens_type'))->toBe(sorted(TokenType::values()));
});

it('rejects a status the enum does not define', function () {
    DB::table('promotions')->insert([
        'product_id' => Product::factory()->create()->id,
        'marketplace_id' => Marketplace::factory()->create()->id,
        'price' => '10.00',
        'confidence' => 'high',
        'status' => 'somehow_invalid',
        'dedupe_hash' => DB::raw("digest('x', 'sha256')"),
    ]);
})->throws(QueryException::class, 'chk_promotions_status');
