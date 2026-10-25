<?php

declare(strict_types=1);

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SchemaInspector;

it('crea las ocho tablas del bloque 5', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'categories',
    'tags',
    'products',
    'product_tag',
    'product_specifications',
    'media',
    'catalogs',
    'catalog_product',
]);

it('ata a su organización las tablas del tenant del bloque', function (string $table): void {
    expect(SchemaInspector::columns($table))->toHaveKey(TenantScope::COLUMN);
})->with(['categories', 'tags', 'products', 'product_specifications', 'catalogs']);

it('declara los dos pivotes como exentos, con su motivo', function (string $pivot): void {
    expect(SchemaInspector::columns($pivot))->not->toHaveKey(TenantScope::COLUMN);
    expect(GlobalUniqueExceptions::allowsMissingTenantColumn($pivot))->toBeTrue();
    expect(GlobalUniqueExceptions::reasonFor($pivot))->toBeString();
})->with(['product_tag', 'catalog_product']);

it('incluye organization_id en todo índice único del bloque', function (string $table): void {
    $offenders = [];

    foreach (Schema::getIndexes($table) as $index) {
        if (! $index['unique'] || $index['primary']) {
            continue;
        }

        if (in_array(TenantScope::COLUMN, $index['columns'], true)) {
            continue;
        }

        foreach ($index['columns'] as $column) {
            if (! GlobalUniqueExceptions::allowsGlobalUnique($table, $column)) {
                $offenders[] = $table.'.'.$column;
            }
        }
    }

    expect($offenders)->toBe([], 'Índices únicos sin organization_id ni excepción declarada: '.implode(', ', $offenders));
})->with(['categories', 'tags', 'products', 'product_specifications', 'catalogs']);

it('mantiene el slug del catálogo único en toda la plataforma', function (): void {
    expect(SchemaInspector::hasUniqueOn('catalogs', 'slug'))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('catalogs', 'organization_id', 'slug'))->toBeFalse();

    expect(GlobalUniqueExceptions::allowsGlobalUnique('catalogs', 'slug'))->toBeTrue();
});

it('acota el sku y el slug del producto a su organización', function (string $column): void {
    expect(SchemaInspector::hasUniqueOn('products', 'organization_id', $column))->toBeTrue();
    expect(SchemaInspector::hasUniqueOn('products', $column))->toBeFalse();
})->with(['sku', 'slug']);

it('indexa products con FULLTEXT sobre nombre, descripción y sku', function (): void {
    $fulltext = array_values(array_filter(
        Schema::getIndexes('products'),
        fn (array $index): bool => strtolower((string) $index['type']) === 'fulltext',
    ));

    expect($fulltext)->not->toBe([], 'products no tiene ningún índice FULLTEXT.');
    expect($fulltext[0]['columns'])->toEqualCanonicalizing(['name', 'description', 'sku']);
});

it('indexa los productos por organización y estado, en ese orden', function (): void {
    expect(SchemaInspector::hasIndexOn('products', 'organization_id', 'status'))->toBeTrue();
});

it('guarda los tres importes del producto como BIGINT de céntimos', function (string $column): void {
    $definition = SchemaInspector::columns('products')[$column];

    expect(strtolower($definition['type_name']))->toBe('bigint');
    expect(strtolower($definition['type']))->not->toContain('unsigned');
})->with(['price_cents', 'compare_at_price_cents', 'down_payment_cents']);

it('deja opcional el precio tachado y obligatorios los otros dos', function (): void {
    $columns = SchemaInspector::columns('products');

    expect($columns['compare_at_price_cents']['nullable'])->toBeTrue();
    expect($columns['price_cents']['nullable'])->toBeFalse();
    expect($columns['down_payment_cents']['nullable'])->toBeFalse();
});

it('borra los productos de forma lógica', function (): void {
    expect(SchemaInspector::columns('products'))->toHaveKey('deleted_at');
});

it('protege la categoría con una clave ajena restrictiva', function (): void {
    $keys = array_values(array_filter(
        Schema::getForeignKeys('products'),
        fn (array $key): bool => $key['columns'] === ['category_id'],
    ));

    expect($keys)->toHaveCount(1);
    expect(strtolower((string) $keys[0]['on_delete']))->toBe('restrict');
});

it('deja catalog_product con created_at y sin updated_at', function (): void {
    $columns = SchemaInspector::columns('catalog_product');

    expect($columns)->toHaveKey('created_at');
    expect($columns)->not->toHaveKey('updated_at');
});

it('indexa el pivote por catálogo y fecha de entrada', function (): void {
    expect(SchemaInspector::hasIndexOn('catalog_product', 'catalog_id', 'created_at'))->toBeTrue();
});

it('indexa el pivote por catálogo y posición', function (): void {
    expect(SchemaInspector::hasIndexOn('catalog_product', 'catalog_id', 'position'))->toBeTrue();
});

it('usa clave primaria compuesta en los dos pivotes', function (string $pivot, array $columns): void {
    $primary = array_values(array_filter(
        SchemaInspector::indexes($pivot),
        fn (array $index): bool => $index['primary'],
    ));

    expect($primary)->toHaveCount(1);
    expect($primary[0]['columns'])->toBe($columns);
})->with([
    ['product_tag', ['product_id', 'tag_id']],
    ['catalog_product', ['catalog_id', 'product_id']],
]);

it('persiste el estado del producto como varchar', function (): void {
    expect(SchemaInspector::columns('products')['status']['type_name'])->toBe('varchar');
});

it('declara sin signo las cantidades que no son dinero', function (string $table, string $column): void {
    expect(strtolower(SchemaInspector::columns($table)[$column]['type']))->toContain('unsigned');
})->with([
    ['products', 'stock'],
    ['products', 'installments_count'],
    ['products', 'frequency_days'],
    ['categories', 'position'],
    ['product_specifications', 'position'],
    ['catalog_product', 'position'],
]);
