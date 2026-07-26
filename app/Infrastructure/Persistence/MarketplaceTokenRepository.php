<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Marketplace\TokenType;
use App\Models\Marketplace;
use App\Models\MarketplaceToken;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MarketplaceTokenRepository
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    public function current(Marketplace $marketplace, TokenType $type = TokenType::Access): ?MarketplaceToken
    {
        return MarketplaceToken::query()
            ->where('marketplace_id', $marketplace->id)
            ->where('type', $type)
            ->current()
            ->first();
    }

    /**
     * Stores a freshly issued token, retiring the one it replaces.
     *
     * The old row is stamped rather than overwritten, so an investigation into a
     * call that failed at 03:00 can still tell which credential was in play. The
     * partial unique index on (marketplace_id, type) WHERE rotated_at IS NULL is
     * what guarantees the two steps leave exactly one current token behind.
     */
    /**
     * Accepts any DateTimeInterface: callers naturally reach for now()->addHour(),
     * which is a mutable Carbon, while connectors compute CarbonImmutable.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function store(
        Marketplace $marketplace,
        TokenType $type,
        string $value,
        ?DateTimeInterface $expiresAt = null,
        array $metadata = [],
    ): MarketplaceToken {
        $expiresAt = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt);

        return $this->connection()->transaction(function () use ($marketplace, $type, $value, $expiresAt, $metadata): MarketplaceToken {
            MarketplaceToken::query()
                ->where('marketplace_id', $marketplace->id)
                ->where('type', $type)
                ->current()
                ->update(['rotated_at' => now(), 'updated_at' => now()]);

            return MarketplaceToken::query()->create([
                'marketplace_id' => $marketplace->id,
                'type' => $type,
                'value' => $value,
                'expires_at' => $expiresAt,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Marketplaces whose access token expires inside the window, so it can be
     * renewed before a fetch run trips over it.
     *
     * @return Collection<int, Marketplace>
     */
    public function marketplacesNeedingRefresh(?int $withinMinutes = null): Collection
    {
        $minutes = $withinMinutes ?? (int) config('promohub.tokens.refresh_ahead_minutes', 120);

        return Marketplace::query()
            ->where('is_active', true)
            ->whereHas('tokens', function ($query) use ($minutes): void {
                $query->where('type', TokenType::Access)
                    ->whereNull('rotated_at')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now()->addMinutes($minutes));
            })
            ->get();
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
