<?php

declare(strict_types=1);

/*
 * Encodes the layering rules from §10 so they are enforced by the suite rather
 * than by review. These run against whatever exists today and keep holding as
 * the contexts fill up.
 */

arch('the domain layer stays free of the framework')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate\Database',
        'Illuminate\Support\Facades',
        'Illuminate\Http',
        'Illuminate\Foundation',
    ]);

arch('only the infrastructure layer talks to the database directly')
    ->expect('Illuminate\Support\Facades\DB')
    ->toOnlyBeUsedIn('App\Infrastructure');

arch('controllers do not reach for repositories or the query builder')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'App\Infrastructure\Persistence',
        'Illuminate\Support\Facades\DB',
    ]);

arch('the domain layer does not depend on outer layers')
    ->expect('App\Domain')
    ->not->toUse([
        'App\Application',
        'App\Infrastructure',
        'App\Http',
        'App\Models',
    ]);

arch('application services stay unaware of HTTP')
    ->expect('App\Application')
    ->not->toUse([
        'Illuminate\Http\Request',
        'App\Http',
    ]);

arch('every class declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('nothing debugging is left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();
