<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Publishing\Channel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cooldown reservation: this product must not be published again on this
 * channel while this range is live.
 *
 * The range column has no Eloquent cast because it is never edited from PHP.
 * Rows are inserted by PublicationWindowGuard, which lets the exclusion
 * constraint arbitrate, and are otherwise read only.
 *
 * @property Channel $channel
 * @property string $window
 */
final class PublicationWindow extends Model
{
    protected $table = 'publication_windows';

    public $timestamps = false;

    protected $fillable = ['product_id', 'channel', 'window', 'promotion_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'created_at' => 'datetime',
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
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
