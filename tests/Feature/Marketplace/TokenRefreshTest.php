<?php

declare(strict_types=1);

use App\Domain\Marketplace\TokenType;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Marketplace\Connectors\MercadoLivreConnector;
use App\Infrastructure\Persistence\MarketplaceTokenRepository;
use App\Models\Marketplace;
use App\Models\MarketplaceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tokens = new MarketplaceTokenRepository;
    $this->marketplace = Marketplace::factory()->create([
        'connector' => MercadoLivreConnector::class,
        'rate_limit_per_minute' => 600,
        'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
    ]);
});

it('finds marketplaces whose token expires inside the window', function () {
    MarketplaceToken::factory()->for($this->marketplace)->create(['expires_at' => now()->addMinutes(30)]);

    $healthy = Marketplace::factory()->create();
    MarketplaceToken::factory()->for($healthy)->create(['expires_at' => now()->addDays(5)]);

    $due = $this->tokens->marketplacesNeedingRefresh(120)->pluck('id');

    expect($due)->toContain($this->marketplace->id)
        ->and($due)->not->toContain($healthy->id);
});

it('ignores tokens that were already rotated', function () {
    MarketplaceToken::factory()->for($this->marketplace)->rotated()->create(['expires_at' => now()->addMinute()]);

    expect($this->tokens->marketplacesNeedingRefresh(120))->toBeEmpty();
});

it('ignores inactive marketplaces', function () {
    $this->marketplace->update(['is_active' => false]);
    MarketplaceToken::factory()->for($this->marketplace)->create(['expires_at' => now()->addMinute()]);

    expect($this->tokens->marketplacesNeedingRefresh(120))->toBeEmpty();
});

it('keeps exactly one current token per type after rotation', function () {
    $this->tokens->store($this->marketplace, TokenType::Access, 'first', now()->addHour());
    $this->tokens->store($this->marketplace, TokenType::Access, 'second', now()->addHour());

    $current = $this->tokens->current($this->marketplace, TokenType::Access);

    expect($current?->value)->toBe('second')
        ->and(MarketplaceToken::query()->whereNull('rotated_at')->count())->toBe(1)
        // The superseded row survives, so an audit can tell which credential a
        // failed call actually used.
        ->and(MarketplaceToken::query()->whereNotNull('rotated_at')->count())->toBe(1);
});

it('stops a second worker from refreshing the same marketplace concurrently', function () {
    $name = "token_refresh:{$this->marketplace->id}";

    /*
     * A genuine contention test needs two connections: an advisory lock is
     * re-entrant within one session, so a single connection would always succeed
     * and prove nothing. pgsql_worker points at the same database over a separate
     * PDO handle.
     */
    $holder = DB::connection('pgsql_worker');
    $holder->beginTransaction();
    $holder->selectOne('SELECT pg_try_advisory_xact_lock(?) AS locked', [crc32($name)]);

    $ran = false;
    $result = (new AdvisoryLock)->attempt($name, function () use (&$ran): string {
        $ran = true;

        return 'renewed';
    });

    $holder->rollBack();

    // The loser skips rather than competing: two simultaneous rotations would each
    // invalidate the other's brand new token.
    expect($result)->toBeNull()
        ->and($ran)->toBeFalse();
});

it('runs the callback when the lock is free', function () {
    $result = (new AdvisoryLock)->attempt('token_refresh:free', fn (): string => 'renewed');

    expect($result)->toBe('renewed');
});

it('releases the lock when the transaction ends, even on failure', function () {
    $name = 'token_refresh:released';

    try {
        (new AdvisoryLock)->attempt($name, function (): never {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // Transaction scoped locks are freed by the transaction ending. A session
    // scoped lock would still be held here and would leak if the worker died.
    expect((new AdvisoryLock)->attempt($name, fn (): string => 'acquired'))->toBe('acquired');
});

it('renews a token through the artisan command', function () {
    Http::fake(['*/oauth/token' => Http::response([
        'access_token' => 'APP_USR-renewed',
        'expires_in' => 21600,
    ])]);

    MarketplaceToken::factory()->for($this->marketplace)->create([
        'value' => 'about-to-expire',
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->artisan('promohub:tokens:refresh')->assertSuccessful();

    expect($this->tokens->current($this->marketplace, TokenType::Access)?->value)->toBe('APP_USR-renewed');
});

it('reports nothing to do when no token is close to expiring', function () {
    MarketplaceToken::factory()->for($this->marketplace)->create(['expires_at' => now()->addDays(10)]);

    $this->artisan('promohub:tokens:refresh')
        ->expectsOutputToContain('No tokens are close to expiring')
        ->assertSuccessful();
});
