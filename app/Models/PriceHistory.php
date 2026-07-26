<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceSource;
use App\Infrastructure\Persistence\Casts\MoneyCast;
use Database\Factories\PriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single price observation.
 *
 * The table is range partitioned and append only. Its real primary key is
 * (id, collected_at) — a partitioned table requires the partition key in every
 * unique index — but Eloquent is told about id alone, which is safe precisely
 * because rows are never updated or deleted individually. Retention happens by
 * dropping whole partitions, not by touching rows.
 *
 * @property Money $price
 * @property Money|null $list_price
 * @property PriceSource $source
 * @property Carbon $collected_at
 */
final class PriceHistory extends Model
{
    /** @use HasFactory<PriceHistoryFactory> */
    use HasFactory;

    protected $table = 'price_history';

    /**
     * collected_at is the time column, and there is no updated_at: an observation
     * is never revised.
     */
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'marketplace_id',
        'price',
        'list_price',
        'in_stock',
        'source',
        'collected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'list_price' => MoneyCast::class,
            'in_stock' => 'boolean',
            'source' => PriceSource::class,
            'collected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
