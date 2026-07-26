<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Guards the database foundation every later migration builds on. If any of
 * these fail, the schema in Phase 2 cannot be created at all: generated columns
 * refuse non-immutable functions, and money columns refuse an absent domain.
 */

it('has every required extension installed', function () {
    $installed = DB::table('pg_extension')->pluck('extname')->all();

    expect($installed)->toContain(
        'pg_trgm', 'unaccent', 'btree_gin', 'btree_gist', 'pgcrypto', 'pg_stat_statements', 'citext',
    );
});

it('exposes an immutable unaccent wrapper usable in index expressions', function () {
    $volatility = DB::scalar("SELECT provolatile FROM pg_proc WHERE proname = 'immutable_unaccent'");

    // 'i' is IMMUTABLE. The extension's own unaccent() is 's' (STABLE) and would
    // be rejected by PostgreSQL in a generated column or index expression.
    expect($volatility)->toBe('i')
        ->and(DB::scalar('SELECT immutable_unaccent(?)', ['Ação Café Eletrônicos']))
        ->toBe('Acao Cafe Eletronicos');
});

it('registers the accent insensitive portuguese search configuration', function () {
    expect(DB::scalar("SELECT count(*) FROM pg_ts_config WHERE cfgname = 'pt_unaccent'"))->toBe(1);
});

it('builds a weighted product search vector', function () {
    $vector = DB::scalar('SELECT product_search_vector(?, ?, ?)::text', [
        'Cafeteira Elétrica', 'Mondial', 'Eletroportáteis',
    ]);

    // Title terms carry weight A, brand B, category C, so ts_rank_cd ranks a
    // title match above a brand match without the caller weighting anything.
    expect($vector)->toContain('cafeteir', ':1A')
        ->and($vector)->toContain('mondial')
        ->and($vector)->toContain('eletroportat');
});

it('matches search terms regardless of accents', function () {
    $matches = DB::scalar(
        "SELECT product_search_vector(?, null, null) @@ websearch_to_tsquery('public.pt_unaccent', ?)",
        ['Cafeteira Elétrica Inox', 'cafeteira eletrica']
    );

    expect($matches)->toBeTrue();
});

it('keeps the product search vector immutable so it can back a generated column', function () {
    $volatility = DB::scalar("SELECT provolatile FROM pg_proc WHERE proname = 'product_search_vector'");

    expect($volatility)->toBe('i');
});

it('stores money as a two decimal domain', function () {
    expect(DB::scalar('SELECT (?::money_brl)::text', ['19.999']))->toBe('20.00')
        ->and(DB::scalar("SELECT data_type || '(' || numeric_precision || ',' || numeric_scale || ')'
            FROM information_schema.domains WHERE domain_name = 'money_brl'"))
        ->toBe('numeric(12,2)');
});

it('rejects negative money values at the database level', function () {
    DB::scalar('SELECT (?::money_brl)', ['-1.00']);
})->throws(QueryException::class, 'money_brl_check');
