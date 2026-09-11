<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Convenciones de columna de [05 §4] y [02 §5], comprobadas contra el esquema
 * real y no contra el texto de las migraciones.
 *
 * `REQ-FX-001` RB-1: todo importe es `BIGINT` de centavos de USD con sufijo
 * `_cents`, y toda tasa es `DECIMAL(18,8)`. La coma flotante no produce un
 * error: produce un balance equivocado de un céntimo que nadie ve hasta que un
 * cliente reclama, y para entonces el dato ya está persistido mal.
 *
 * Estas comprobaciones recorren **todas** las tablas, no solo las del bloque
 * 1, así que siguen valiendo según crezca el esquema.
 */

/**
 * Tablas que no son nuestras y cuyo esquema no controlamos.
 *
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

/**
 * La cara contraria: una columna de dinero que **no** termina en `_cents` es
 * la forma en que la coma flotante vuelve a entrar sin que nadie la vea.
 */
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

        // `declared_amount` es la cantidad en la moneda que declaró el cliente
        // (`receipts`, `payments`) y no es un importe en centavos de USD; llega
        // con el bloque 7 y tiene su propia convención.
        if (in_array($column['name'], ['declared_amount'], true)) {
            continue;
        }

        $offenders[] = $column['table'].'.'.$column['name'];
    }

    expect($offenders)->toBe([], 'Columnas monetarias sin sufijo _cents: '.implode(', ', $offenders));
});

it('declara toda tasa de cambio como DECIMAL(18,8)', function (): void {
    $offenders = [];

    foreach (projectColumns() as $column) {
        if (strtolower($column['type_name']) !== 'decimal') {
            continue;
        }

        if (! preg_match('/decimal\(18\s*,\s*8\)/i', $column['type'])) {
            $offenders[] = $column['table'].'.'.$column['name'].' es '.$column['type'];
        }
    }

    expect($offenders)->toBe([], 'Decimales fuera de (18,8): '.implode(', ', $offenders));
});

/**
 * [02 §5]: los enums de dominio se persisten como `VARCHAR` respaldados por un
 * enum de PHP. Un `ENUM` de MySQL obliga a una migración de alteración cada
 * vez que aparece un caso nuevo.
 */
it('no usa ENUM de MySQL para los enums de dominio', function (): void {
    $offenders = [];

    foreach (projectColumns() as $column) {
        if (strtolower($column['type_name']) === 'enum') {
            $offenders[] = $column['table'].'.'.$column['name'];
        }
    }

    expect($offenders)->toBe([], 'Columnas ENUM de MySQL: '.implode(', ', $offenders));
});

/**
 * [02 §5]: `id` es `BIGINT UNSIGNED AUTO_INCREMENT`.
 */
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
