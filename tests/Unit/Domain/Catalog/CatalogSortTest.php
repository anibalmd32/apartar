<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogSort;

it('acepta de la URL los cinco órdenes que se eligen', function (string $value, CatalogSort $sort): void {
    expect(CatalogSort::whitelist()->resolve($value))->toBe($sort);
})->with([
    ['curated', CatalogSort::Curated],
    ['recent', CatalogSort::Recent],
    ['price_asc', CatalogSort::PriceAsc],
    ['price_desc', CatalogSort::PriceDesc],
    ['name', CatalogSort::Name],
]);

it('ignora lo que no está en la lista, sin interpretarlo', function (mixed $requested): void {
    expect(CatalogSort::whitelist()->resolve($requested))->toBeNull();
})->with([
    'nada' => [null],
    'vacío' => [''],
    'desconocido' => ['popular'],
    'inyección' => ['price_asc; DROP TABLE products'],
    'mayúsculas' => ['Name'],
    'con espacios' => [' name '],
    'un array de la query string' => [['name']],
    'demasiado largo' => [str_repeat('n', 40)],
    'la relevancia' => ['relevance'],
]);

it('da a cada orden por columnas un desempate por id en su misma dirección', function (CatalogSort $sort): void {
    $keyset = (array) $sort->keyset();

    expect($keyset)->not->toBe([])
        ->and(array_key_last($keyset))->toBe('products.id')
        ->and(array_unique(array_values($keyset)))->toHaveCount(1);
})->with([CatalogSort::Curated, CatalogSort::Recent, CatalogSort::PriceAsc, CatalogSort::PriceDesc, CatalogSort::Name]);

it('pagina la relevancia sin columnas, por desplazamiento', function (): void {
    expect(CatalogSort::Relevance->keyset())->toBeNull();
});

it('declara en el SELECT todo alias por el que ordena', function (): void {
    $aliases = array_map(fn (string $select): string => substr($select, (int) strripos($select, ' as ') + 4), CatalogSort::SELECTS);

    foreach (CatalogSort::cases() as $sort) {
        foreach (array_keys((array) $sort->keyset()) as $column) {
            expect(str_starts_with($column, 'products.') || in_array($column, $aliases, true))->toBeTrue($column);
        }
    }
});
