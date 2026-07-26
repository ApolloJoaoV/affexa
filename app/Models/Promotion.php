<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\Money;
use App\Domain\Promotion\PromotionConfidence;
use App\Domain\Promotion\PromotionStatus;
use App\Domain\Promotion\RejectionReason;
use App\Infrastructure\Persistence\Casts\MoneyCast;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Money $price
 * @property Money|null $previous_price
 * @property int $score
 * @property array<string, mixed> $score_breakdown
 * @property PromotionStatus $status
 * @property PromotionConfidence $confidence
 * @property RejectionReason|null $rejection_reason
 */
final class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'marketplace_id',
        'price',
        'previous_price',
        'discount_percent',
        'score',
        'score_breakdown',
        'confidence',
        'status',
        'rejection_reason',
        'evaluated_at',
        'approved_at',
        'published_at',
        'expires_at',
        'dedupe_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'previous_price' => MoneyCast::class,
            'discount_percent' => 'integer',
            'score' => 'integer',
            'score_breakdown' => 'array',
            'status' => PromotionStatus::class,
            'confidence' => PromotionConfidence::class,
            'rejection_reason' => RejectionReason::class,
            'evaluated_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Marketplace, $this>
     */
    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    /**
     * @return HasMany<Publication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    /**
     * The approval queue, highest scoring first. Matches idx_promotions_pending.
     *
     * @param  Builder<Promotion>  $query
     */
    public function scopeAwaitingApproval(Builder $query): void
    {
        $query->where('status', PromotionStatus::Pending)
            ->orderByDesc('score')
            ->orderByDesc('evaluated_at');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
