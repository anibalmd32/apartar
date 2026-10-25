<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

it('crea las seis tablas del bloque 1', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['platform_users', 'plans', 'organizations', 'exchange_rates', 'accounts', 'platform_settings']);

it('declara platform_users con sus columnas e índice', function (): void {
    $columns = SchemaInspector::columns('platform_users');

    expect(array_keys($columns))->toContain('id', 'name', 'email', 'password', 'disabled_at', 'must_change_password');

    expect($columns['disabled_at']['nullable'])->toBeTrue();
    expect($columns['must_change_password']['nullable'])->toBeFalse();

    expect(SchemaInspector::hasUniqueOn('platform_users', 'email'))->toBeTrue();
});

it('mantiene platform_users y users como tablas distintas', function (): void {
    expect(SchemaInspector::columns('platform_users'))->not->toHaveKey('organization_id');
    expect(SchemaInspector::columns('platform_users'))->not->toHaveKey('role');
});

it('declara las siete columnas de límite de plans como nullable', function (string $column): void {
    $columns = SchemaInspector::columns('plans');

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
    $columns = SchemaInspector::columns('plans');

    expect(array_keys($columns))
        ->toContain('code', 'name', 'price_cents', 'interval', 'is_public', 'position', 'features');

    expect($columns['features']['type_name'])->toBe('json');
    expect(SchemaInspector::hasUniqueOn('plans', 'code'))->toBeTrue();
});

it('declara organizations con sus columnas e índices', function (): void {
    $columns = SchemaInspector::columns('organizations');

    expect(array_keys($columns))->toContain(
        'name', 'slug', 'document', 'timezone', 'logo_path',
        'brand_color', 'storefront_enabled', 'status', 'suspended_at', 'deleted_at',
    );

    expect($columns['document']['nullable'])->toBeTrue();
    expect($columns['suspended_at']['nullable'])->toBeTrue();
    expect($columns['deleted_at']['nullable'])->toBeTrue();

    expect(SchemaInspector::hasUniqueOn('organizations', 'slug'))->toBeTrue();
    expect(SchemaInspector::hasIndexOn('organizations', 'status'))->toBeTrue();
});

it('no pone organization_id en organizations', function (): void {
    expect(SchemaInspector::columns('organizations'))->not->toHaveKey('organization_id');
});

it('persiste el estado de la organización como varchar', function (): void {
    expect(SchemaInspector::columns('organizations')['status']['type_name'])->toBe('varchar');
});

it('declara las dos marcas de tiempo de exchange_rates', function (): void {
    $columns = SchemaInspector::columns('exchange_rates');

    expect(array_keys($columns))->toContain('source', 'rate', 'published_at', 'fetched_at');
    expect($columns['published_at']['nullable'])->toBeFalse();
    expect($columns['fetched_at']['nullable'])->toBeFalse();
});

it('indexa exchange_rates por fuente y fecha de publicación', function (): void {
    expect(SchemaInspector::hasIndexOn('exchange_rates', 'source', 'published_at'))->toBeTrue();
    expect(SchemaInspector::hasIndexOn('exchange_rates', 'source', 'fetched_at'))->toBeFalse();
});

it('no guarda documento ni teléfono en accounts', function (): void {
    $columns = array_keys(SchemaInspector::columns('accounts'));

    $forbidden = array_values(array_filter(
        $columns,
        fn (string $column): bool => (bool) preg_match(
            '/(document|dni|cedula|rif|passport|phone|mobile|telefono|whatsapp)/i',
            $column,
        ),
    ));

    expect($forbidden)->toBe([], 'accounts es identidad de navegación, no comercial: '.implode(', ', $forbidden));
});

it('indexa el email de accounts sin hacerlo único', function (): void {
    expect(SchemaInspector::hasIndexOn('accounts', 'email'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('accounts', 'email'))->toBeFalse();
});

it('usa key como clave primaria de platform_settings', function (): void {
    $primary = array_values(array_filter(
        SchemaInspector::indexes('platform_settings'),
        fn (array $index): bool => $index['primary'],
    ));

    expect($primary)->toHaveCount(1);
    expect($primary[0]['columns'])->toBe(['key']);

    expect(SchemaInspector::columns('platform_settings'))->not->toHaveKey('id');
    expect(SchemaInspector::columns('platform_settings')['value']['type_name'])->toBe('json');
});

it('deja platform_settings.updated_by nullable y apuntando a platform_users', function (): void {
    expect(SchemaInspector::columns('platform_settings')['updated_by']['nullable'])->toBeTrue();

    $referenced = array_map(
        fn (array $key): string => $key['foreign_table'],
        Schema::getForeignKeys('platform_settings'),
    );

    expect($referenced)->toContain('platform_users');
});
