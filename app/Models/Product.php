<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\Money;
use App\Infrastructure\Persistence\Casts\MoneyCast;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $marketplace_id
 * @property int|null $category_id
 * @property string $public_id
 * @property string $title
 * @property string $normalized_title
 * @property Money|null $current_price
 * @property Money|null $previous_price
 * @property Money|null $lowest_price_ever
 * @property Money|null $highest_price_ever
 * @property int $discount_percent
 * @property bool $in_stock
 * @property float|null $rating
 * @property int $reviews_count
 * @property bool $is_prime
 * @property bool $has_free_shipping
 * @property-read Marketplace $marketplace
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * discount_percent and search_vector are absent on purpose: both are
     * GENERATED ALWAYS columns, so PostgreSQL rejects any attempt to write them.
     */
    protected $fillable = [
        'marketplace_id',
        'external_id',
        'identity_hash',
        'title',
        'normalized_title',
        'brand',
        'category_id',
        'image_url',
        'product_url',
        'affiliate_url',
        'rating',
        'reviews_count',
        'is_prime',
        'has_free_shipping',
        'in_stock',
        'current_price',
        'previous_price',
        'lowest_price_ever',
        'highest_price_ever',
        'raw_payload',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * Never select these implicitly. raw_payload can be large and search_vector is
     * meaningless to the application; shipping either across the wire on every
     * listing query is pure waste.
     *
     * @var list<string>
     */
    public const HEAVY_COLUMNS = ['raw_payload', 'search_vector'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_price' => MoneyCast::class,
            'previous_price' => MoneyCast::class,
            'lowest_price_ever' => MoneyCast::class,
            'highest_price_ever' => MoneyCast::class,
            'rating' => 'float',
            'reviews_count' => 'integer',
            'discount_percent' => 'integer',
            'is_prime' => 'boolean',
            'has_free_shipping' => 'boolean',
            'in_stock' => 'boolean',
            'raw_payload' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Marketplace, $this>
     */
    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<PriceHistory, $this>
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    /**
     * @return HasMany<Promotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * Excludes the large columns from the selected set.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithoutHeavyColumns(Builder $query): void
    {
        $columns = array_diff(
            $this->getConnection()->getSchemaBuilder()->getColumnListing($this->getTable()),
            self::HEAVY_COLUMNS,
        );

        $query->select(array_map(fn (string $column): string => $this->qualifyColumn($column), $columns));
    }

    /**
     * Routes the URL key through public_id so that neither record volume nor
     * enumerable identifiers are exposed.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
