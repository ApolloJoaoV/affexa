<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Marketplace\TokenType;
use Database\Factories\MarketplaceTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $marketplace_id
 * @property TokenType $type
 * @property string $value
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $rotated_at
 */
final class MarketplaceToken extends Model
{
    /** @use HasFactory<MarketplaceTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'marketplace_id',
        'type',
        'value',
        'expires_at',
        'rotated_at',
        'metadata',
    ];

    protected $hidden = ['value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TokenType::class,
            'value' => 'encrypted',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
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
     * The token of its type currently in use. Rotated rows are kept for audit and
     * excluded here by the same predicate that backs the partial unique index.
     *
     * @param  Builder<MarketplaceToken>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('rotated_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresWithin(int $minutes): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo(now()->addMinutes($minutes));
    }
}
