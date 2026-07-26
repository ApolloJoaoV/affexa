<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Publishing\Channel;
use App\Domain\Publishing\PublicationStatus;
use Database\Factories\PublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Channel $channel
 * @property PublicationStatus $status
 * @property int $attempts
 */
final class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'channel',
        'provider',
        'recipient',
        'status',
        'message_body',
        'card_path',
        'provider_message_id',
        'attempts',
        'error_code',
        'error_message',
        'scheduled_for',
        'sent_at',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => PublicationStatus::class,
            'attempts' => 'integer',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
