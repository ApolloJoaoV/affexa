<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MarketplaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $name
 * @property class-string $connector
 * @property bool $is_active
 * @property int $trust_score
 * @property int $fetch_interval_minutes
 * @property int $rate_limit_per_minute
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property int $consecutive_failures
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $last_error_at
 * @property string|null $last_error_message
 * @property Carbon|null $circuit_open_until
 */
final class Marketplace extends Model
{
    /** @use HasFactory<MarketplaceFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'connector',
        'is_active',
        'trust_score',
        'fetch_interval_minutes',
        'rate_limit_per_minute',
        'credentials',
        'settings',
        'last_fetched_at',
        'last_error_at',
        'last_error_message',
        'consecutive_failures',
        'circuit_open_until',
    ];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trust_score' => 'integer',
            'fetch_interval_minutes' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'consecutive_failures' => 'integer',
            // Encrypted by the application, so the key never lives in the database
            // and a stolen dump alone cannot decrypt the credentials. Stored as a
            // map because every marketplace needs a different set of keys.
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_fetched_at' => 'datetime',
            'last_error_at' => 'datetime',
            'circuit_open_until' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MarketplaceToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(MarketplaceToken::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * True while the circuit breaker is holding this marketplace out of the
     * rotation after repeated failures.
     */
    public function hasOpenCircuit(): bool
    {
        return $this->circuit_open_until !== null
            && $this->circuit_open_until->isFuture();
    }

    /**
     * A single credential value, e.g. client_id. Returns null when unset so a
     * connector can fail with a clear message rather than an array key warning.
     */
    public function credential(string $key): ?string
    {
        $credentials = $this->credentials;

        if (! is_array($credentials)) {
            return null;
        }

        $value = $credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A value from the free-form settings map, e.g. the affiliate tag.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings;

        return is_array($settings) ? ($settings[$key] ?? $default) : $default;
    }
}
