<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * What a fetch run is looking for.
 *
 * Connectors translate this into whatever their marketplace understands; nothing
 * marketplace specific belongs here.
 */
final readonly class FetchCriteria
{
    /**
     * @param  list<string>  $categoryExternalIds  restrict to these marketplace side categories
     * @param  int  $maxItems  stop after this many products, so one misbehaving
     *                         marketplace cannot monopolise a worker
     */
    public function __construct(
        public array $categoryExternalIds = [],
        public ?int $minDiscountPercent = null,
        public int $maxItems = 1000,
        public int $pageSize = 50,
    ) {}

    public static function default(): self
    {
        return new self;
    }

    /**
     * @param  list<string>  $categoryExternalIds
     */
    public function forCategories(array $categoryExternalIds): self
    {
        return new self($categoryExternalIds, $this->minDiscountPercent, $this->maxItems, $this->pageSize);
    }

    public function withMaxItems(int $maxItems): self
    {
        return new self($this->categoryExternalIds, $this->minDiscountPercent, $maxItems, $this->pageSize);
    }

    public function withMinDiscount(int $percent): self
    {
        return new self($this->categoryExternalIds, $percent, $this->maxItems, $this->pageSize);
    }
}
