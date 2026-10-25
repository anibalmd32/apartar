<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * @return list<string>
 */
function foreignTables(): array
{
    return [
        'migrations',
        'media',
        'activity_log',
        'push_subscriptions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
    ];
}

/**
 * @return list<array{table: string, name: string, type_name: string, type: string}>
 */
function projectColumns(): array
{
    $columns = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (in_array($table, foreignTables(), true)) {
            continue;
        }

        foreach (Schema::getColumns($table) as $column) {
            $columns[] = [
                'table' => $table,
                'name' => $column['name'],
                'type_name' => $column['type_name'],
                'type' => $column['type'],
            ];
        }
    }

    return $columns;
}

it('no declara ninguna columna de coma flotante en el esquema', function (): void {
    $offenders = [];

    foreach (projectColumns() as $column) {
        if (in_array(strtolower($column['type_name']), ['float', 'double', 'real'], true)) {
            $offenders[] = $column['table'].'.'.$column['name'];
        }
    }

    expect($offenders)->toBe([], 'Columnas de coma flotante: '.implode(', ', $offenders));
});

it('declara todo importe como BIGINT con sufijo _cents', function (): void {
    $offenders = [];

    foreach (projectColumns() as $column) {
        if (! str_ends_with($column['name'], '_cents')) {
            continue;
        }

        if (strtolower($column['type_name']) !== 'bigint') {
            $offenders[] = $column['table'].'.'.$column['name'].' es '.$column['type_name'];
        }
    }

    expect($offenders)->toBe([], 'Importes que no son BIGINT: '.implode(', ', $offenders));
});

it('nombra con _cents toda columna que hable de dinero', function (): void {
    $offenders = [];

    foreach (projectColumns() as $column) {
        $looksMonetary = (bool) preg_match(
            '/(^|_)(price|amount|total|subtotal|balance|fee|cost)($|_)/i',
            $column['name'],
        );

        if (! $looksMonetary || str_ends_with($column['name'], '_cents')) {
            continue;
        }

        if (in_array($column['name'], ['declared_amount', 'amount_cents_usd', 'settled_amount'], true)) {
            continue;
        }

        $offenders[] = $column['table'].'.'.$column['name'];
    }

    expect($offenders)->toBe([], 'Columnas monetarias sin sufijo _cents: '.implode(', ', $offenders));
});

it('declara toda tasa de cambio como DECIMAL(18,8) y ningún otro decimal sin motivo', function (): void {
    $declaredDecimals = [
        'declared_amount' => 'decimal(16,2)',
        'settled_amount' => 'decimal(16,2)',
    ];

    $offenders = [];

    foreach (projectColumns() as $column) {
        if (strtolower($column['type_name']) !== 'decimal') {
            continue;
        }

        $expected = $declaredDecimals[$column['name']] ?? 'decimal(18,8)';

        if (str_replace(' ', '', strtolower($column['type'])) !== $expected) {
            $offenders[] = $column['table'].'.'.$column['name'].' es '.$column['type'].', se esperaba '.$expected;
        }
    }

    expect($offenders)->toBe([], 'Decimales fuera de su precisión: '.implode(', ', $offenders));
});

it('no usa ENUM de MySQL para los enums de dominio', function (): void {
    $offenders = [];

    foreach (projectColumns() as $column) {
        if (strtolower($column['type_name']) === 'enum') {
            $offenders[] = $column['table'].'.'.$column['name'];
        }
    }

    expect($offenders)->toBe([], 'Columnas ENUM de MySQL: '.implode(', ', $offenders));
});

it('declara todo id como BIGINT UNSIGNED', function (): void {
    $offenders = [];

    foreach (projectColumns() as $column) {
        if ($column['name'] !== 'id') {
            continue;
        }

        if (strtolower($column['type']) !== 'bigint unsigned') {
            $offenders[] = $column['table'].'.id es '.$column['type'];
        }
    }

    expect($offenders)->toBe([], 'Claves primarias fuera de convención: '.implode(', ', $offenders));
});
