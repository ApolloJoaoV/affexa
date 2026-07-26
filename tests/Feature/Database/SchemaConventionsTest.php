<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Enforces the schema conventions from §7 across every table, including the ones
 * published by packages. A mixed schema, where some columns carry a time zone and
 * others do not, is exactly the ambiguity these rules exist to remove.
 */

it('stores every date and time column with a time zone', function () {
    /** @var list<object{table_name: string, column_name: string, data_type: string}> $naive */
    $naive = DB::select(<<<'SQL'
        SELECT table_name, column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND data_type IN ('timestamp without time zone', 'time without time zone')
        ORDER BY table_name, column_name
    SQL);

    $offenders = array_map(
        fn (object $column): string => "{$column->table_name}.{$column->column_name} ({$column->data_type})",
        $naive
    );

    expect($offenders)->toBe([]);
});

it('applies the application time zone to the connection', function () {
    // The database stores UTC; the application presents America/Sao_Paulo.
    expect(config('app.timezone'))->toBe('America/Sao_Paulo')
        ->and(DB::scalar('SHOW TimeZone'))->toBe('America/Sao_Paulo');
});

it('keeps queryable json payloads as jsonb', function () {
    // Only jsonb supports GIN indexes and containment operators, so plain json
    // would quietly rule out the payload queries the pipeline depends on.
    /** @var list<object{table_name: string, column_name: string}> $plainJson */
    $plainJson = DB::select(<<<'SQL'
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = 'public' AND data_type = 'json'
        ORDER BY table_name, column_name
    SQL);

    $offenders = array_map(
        fn (object $column): string => "{$column->table_name}.{$column->column_name}",
        $plainJson
    );

    expect($offenders)->toBe([]);
});
