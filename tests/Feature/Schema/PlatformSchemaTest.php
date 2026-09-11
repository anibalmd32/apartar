<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Bloque 1 del esquema: las seis tablas de plataforma ([05 §4]).
 *
 * Un error aquí no se corrige editando la migración, se corrige con una
 * migración de alteración, y para entonces ya se ha propagado a las tablas que
 * apuntan a estas. De ahí que el esquema se compruebe columna a columna y no
 * solo "la tabla existe".
 */

/**
 * @return array<string, array{type_name: string, nullable: bool, type: string}>
 */
function columnsOf(string $table): array
{
    $columns = [];

    foreach (Schema::getColumns($table) as $column) {
        $columns[$column['name']] = [
            'type_name' => $column['type_name'],
            'nullable' => $column['nullable'],
            'type' => $column['type'],
        ];
    }

    return $columns;
}

/**
 * @return list<array{columns: list<string>, unique: bool, primary: bool}>
 */
function indexesOf(string $table): array
{
    return array_map(
        fn (array $index): array => [
            'columns' => $index['columns'],
            'unique' => $index['unique'],
            'primary' => $index['primary'],
        ],
        Schema::getIndexes($table),
    );
}

function hasIndexOn(string $table, string ...$columns): bool
{
    foreach (indexesOf($table) as $index) {
        if ($index['columns'] === $columns) {
            return true;
        }
    }

    return false;
}

function hasUniqueOn(string $table, string ...$columns): bool
{
    foreach (indexesOf($table) as $index) {
        if ($index['columns'] === $columns && $index['unique']) {
            return true;
        }
    }

    return false;
}

it('crea las seis tablas del bloque 1', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['platform_users', 'plans', 'organizations', 'exchange_rates', 'accounts', 'platform_settings']);

/**
 * `REQ-PLT-001` RB-1: tabla separada de `users` por construcción.
 */
it('declara platform_users con sus columnas e índice', function (): void {
    $columns = columnsOf('platform_users');

    expect(array_keys($columns))->toContain('id', 'name', 'email', 'password', 'disabled_at', 'must_change_password');

    expect($columns['disabled_at']['nullable'])->toBeTrue();
    expect($columns['must_change_password']['nullable'])->toBeFalse();

    expect(hasUniqueOn('platform_users', 'email'))->toBeTrue();
});

it('mantiene platform_users y users como tablas distintas', function (): void {
    expect(columnsOf('platform_users'))->not->toHaveKey('organization_id');
    expect(columnsOf('platform_users'))->not->toHaveKey('role');
});

/**
 * `REQ-SUB-001` RB-2: las siete columnas de límite, todas nullable, porque
 * `NULL` significa **ilimitado**. Una sola de ellas en `NOT NULL DEFAULT 0`
 * convertiría el plan Business en un plan que no deja crear nada.
 */
it('declara las siete columnas de límite de plans como nullable', function (string $column): void {
    $columns = columnsOf('plans');

    expect($columns)->toHaveKey($column);
    expect($columns[$column]['nullable'])->toBeTrue("plans.{$column} debería admitir NULL: NULL significa ilimitado.");
})->with([
    'max_users',
    'max_catalogs',
    'max_products',
    'max_products_per_catalog',
    'max_active_layaways',
    'max_storage_mb',
    'max_payment_accounts',
]);

it('declara el resto de plans según §4', function (): void {
    $columns = columnsOf('plans');

    expect(array_keys($columns))
        ->toContain('code', 'name', 'price_cents', 'interval', 'is_public', 'position', 'features');

    expect($columns['features']['type_name'])->toBe('json');
    expect(hasUniqueOn('plans', 'code'))->toBeTrue();
});

/**
 * `REQ-ORG-008`: `status`, `suspended_at` y `deleted_at` (SoftDeletes).
 */
it('declara organizations con sus columnas e índices', function (): void {
    $columns = columnsOf('organizations');

    expect(array_keys($columns))->toContain(
        'name', 'slug', 'document', 'timezone', 'logo_path',
        'brand_color', 'storefront_enabled', 'status', 'suspended_at', 'deleted_at',
    );

    expect($columns['document']['nullable'])->toBeTrue();
    expect($columns['suspended_at']['nullable'])->toBeTrue();
    expect($columns['deleted_at']['nullable'])->toBeTrue();

    expect(hasUniqueOn('organizations', 'slug'))->toBeTrue();
    expect(hasIndexOn('organizations', 'status'))->toBeTrue();
});

/**
 * El tenant no está sujeto a sí mismo.
 */
it('no pone organization_id en organizations', function (): void {
    expect(columnsOf('organizations'))->not->toHaveKey('organization_id');
});

/**
 * `REQ-ORG-008` RB-1 y `REQ-INF-004` RB-2: el estado se persiste como
 * `VARCHAR` respaldado por un enum de PHP, no como `ENUM` de MySQL, para que
 * añadir un caso no exija una migración de alteración.
 */
it('persiste el estado de la organización como varchar', function (): void {
    expect(columnsOf('organizations')['status']['type_name'])->toBe('varchar');
});

/**
 * `REQ-FX-002` RB-3b: dos marcas de tiempo, y el índice va sobre la de la
 * fuente, no sobre la de la ingesta.
 */
it('declara las dos marcas de tiempo de exchange_rates', function (): void {
    $columns = columnsOf('exchange_rates');

    expect(array_keys($columns))->toContain('source', 'rate', 'published_at', 'fetched_at');
    expect($columns['published_at']['nullable'])->toBeFalse();
    expect($columns['fetched_at']['nullable'])->toBeFalse();
});

it('indexa exchange_rates por fuente y fecha de publicación', function (): void {
    expect(hasIndexOn('exchange_rates', 'source', 'published_at'))->toBeTrue();
    expect(hasIndexOn('exchange_rates', 'source', 'fetched_at'))->toBeFalse();
});

/**
 * `REQ-ACC-001` RB-2. Esta es la aserción que impide que un registro global de
 * cédulas entre por la puerta de atrás: el documento identifica al cliente
 * dentro de un negocio y vive en `customers`, nunca aquí.
 */
it('no guarda documento ni teléfono en accounts', function (): void {
    $columns = array_keys(columnsOf('accounts'));

    $forbidden = array_values(array_filter(
        $columns,
        fn (string $column): bool => (bool) preg_match(
            '/(document|dni|cedula|rif|passport|phone|mobile|telefono|whatsapp)/i',
            $column,
        ),
    ));

    expect($forbidden)->toBe([], 'accounts es identidad de navegación, no comercial: '.implode(', ', $forbidden));
});

/**
 * RB-3: la clave de la cuenta no es el correo. Si `email` fuese `UNIQUE`, una
 * cuenta de Google migrada a otro correo chocaría con la anterior.
 */
it('indexa el email de accounts sin hacerlo único', function (): void {
    expect(hasIndexOn('accounts', 'email'))->toBeTrue();
    expect(hasUniqueOn('accounts', 'email'))->toBeFalse();
});

/**
 * `REQ-PLT-006`: la clave primaria es la cadena `key`, no un `id`.
 */
it('usa key como clave primaria de platform_settings', function (): void {
    $primary = array_values(array_filter(
        indexesOf('platform_settings'),
        fn (array $index): bool => $index['primary'],
    ));

    expect($primary)->toHaveCount(1);
    expect($primary[0]['columns'])->toBe(['key']);

    expect(columnsOf('platform_settings'))->not->toHaveKey('id');
    expect(columnsOf('platform_settings')['value']['type_name'])->toBe('json');
});

it('deja platform_settings.updated_by nullable y apuntando a platform_users', function (): void {
    expect(columnsOf('platform_settings')['updated_by']['nullable'])->toBeTrue();

    $referenced = array_map(
        fn (array $key): string => $key['foreign_table'],
        Schema::getForeignKeys('platform_settings'),
    );

    expect($referenced)->toContain('platform_users');
});
