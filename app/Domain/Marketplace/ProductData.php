<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Pricing\Money;
use InvalidArgumentException;

/**
 * A product as delivered by any marketplace, already normalised.
 *
 * Normalisation is the connector's job, so everything downstream — the upsert, the
 * scoring engine, the message renderer — sees one shape regardless of which API it
 * came from. Adding a marketplace therefore never changes the pipeline.
 */
final readonly class ProductData
{
    /**
     * @param  string|null  $variation  size, colour or similar; part of the identity
     *                                  when a marketplace prices variations separately
     * @param  float|null  $rating  on a 0 to 5 scale, whatever scale the source used
     * @param  array<string, mixed>  $rawPayload  the original response, preserved verbatim
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public string $productUrl,
        public Money $currentPrice,
        public ?Money $previousPrice = null,
        public ?Money $listPrice = null,
        public ?string $brand = null,
        public ?string $variation = null,
        public ?string $categoryExternalId = null,
        public ?string $imageUrl = null,
        public ?string $affiliateUrl = null,
        public ?float $rating = null,
        public int $reviewsCount = 0,
        public bool $isPrime = false,
        public bool $hasFreeShipping = false,
        public bool $inStock = true,
        public array $rawPayload = [],
    ) {
        if ($externalId === '') {
            throw new InvalidArgumentException('A product must carry the marketplace external id.');
        }

        if ($title === '') {
            throw new InvalidArgumentException('A product must carry a title.');
        }

        if ($rating !== null && ($rating < 0 || $rating > 5)) {
            throw new InvalidArgumentException("Rating [{$rating}] is outside the 0 to 5 scale; normalise it in the connector.");
        }

        if ($reviewsCount < 0) {
            throw new InvalidArgumentException('Review count cannot be negative.');
        }
    }

    /**
     * Lowercased, unaccented and unpunctuated title.
     *
     * Stored alongside the original so the same product can be recognised across
     * marketplaces by trigram similarity, where accents and punctuation differ
     * constantly for what is the same item.
     */
    public function normalizedTitle(): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->title);
        $lowered = mb_strtolower($ascii === false ? $this->title : $ascii);
        $stripped = preg_replace('/[^a-z0-9]+/', ' ', $lowered) ?? '';

        return trim(preg_replace('/\s+/', ' ', $stripped) ?? '');
    }

    /**
     * Stable identity for this product within a marketplace.
     *
     * Returned as raw binary because it is stored in a bytea column, where it
     * occupies half the space of the hex representation and therefore halves the
     * unique index too.
     */
    public function identityHash(string $marketplaceSlug): string
    {
        $parts = [mb_strtolower($marketplaceSlug), $this->externalId, $this->variation ?? ''];

        return hash('sha256', implode('|', $parts), binary: true);
    }

    /**
     * Discount against the previous price we ourselves observed.
     *
     * Deliberately ignores listPrice: marketplaces inflate it routinely, and using
     * it here would manufacture discounts that never existed.
     */
    public function discountPercent(): int
    {
        if ($this->previousPrice === null || $this->previousPrice->isZero()) {
            return 0;
        }

        $saved = $this->previousPrice->minus($this->currentPrice)->cents;

        return (int) floor($saved / $this->previousPrice->cents * 100);
    }

    public function savings(): ?Money
    {
        if ($this->previousPrice === null) {
            return null;
        }

        return $this->previousPrice->minus($this->currentPrice);
    }
}
