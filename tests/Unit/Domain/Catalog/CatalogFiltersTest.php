<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogFilters;
use App\Domain\Catalog\CatalogSort;
use App\Domain\Catalog\SearchMode;
use Illuminate\Http\Request;

function filtersFrom(string $query): CatalogFilters
{
    return CatalogFilters::fromRequest(Request::create('/c/ofertas?'.$query));
}

it('lee la URL que escribe la página', function (): void {
    $filters = filtersFrom('q=taladro&category=herramientas&tags[]=oferta&tags[]=nuevo&sort=price_asc');

    expect($filters->toArray())->toBe([
        'q' => 'taladro',
        'category' => 'herramientas',
        'tags' => ['oferta', 'nuevo'],
        'sort' => 'price_asc',
        'ignored' => false,
    ]);
});

it('sin nada en la URL no filtra nada ni avisa de nada', function (): void {
    expect(filtersFrom('')->toArray())->toBe(['q' => null, 'category' => null, 'tags' => [], 'sort' => null, 'ignored' => false]);
});

it('entiende una etiqueta sin corchetes y no repite las repetidas', function (): void {
    expect(filtersFrom('tags=oferta')->tags)->toBe(['oferta'])
        ->and(filtersFrom('tags[]=oferta&tags[]=oferta')->tags)->toBe(['oferta']);
});

it('descarta con aviso lo que no tiene forma de slug', function (string $query): void {
    $filters = filtersFrom($query);

    expect($filters->category)->toBeNull()
        ->and($filters->tags)->toBe([])
        ->and($filters->ignored)->toBeTrue();
})->with([
    'categoría con etiquetas HTML' => ['category=%3Cscript%3E'],
    'categoría en mayúsculas' => ['category=Herramientas'],
    'categoría como lista' => ['category[]=herramientas'],
    'etiqueta con espacios' => ['tags[]=con%20espacio'],
]);

it('no lee más etiquetas de las que un panel puede ofrecer', function (): void {
    $query = implode('&', array_map(fn (int $n): string => 'tags[]=t'.$n, range(1, 30)));

    expect(filtersFrom($query)->tags)->toHaveCount(CatalogFilters::MAX_TAGS);
});

it('se queda solo con lo que el catálogo ofrece', function (): void {
    $filters = filtersFrom('category=pinturas&tags[]=oferta&tags[]=borrada')->restrictedTo(['herramientas'], ['oferta']);

    expect($filters->category)->toBeNull()
        ->and($filters->tags)->toBe(['oferta'])
        ->and($filters->ignored)->toBeTrue();
});

it('no avisa si todo lo pedido está en el catálogo', function (): void {
    $filters = filtersFrom('category=herramientas&tags[]=oferta')->restrictedTo(['herramientas'], ['oferta', 'nuevo']);

    expect($filters->category)->toBe('herramientas')
        ->and($filters->ignored)->toBeFalse();
});

it('ignora en silencio el orden inválido y el término que se queda en nada', function (): void {
    $filters = filtersFrom('sort=price_asc%3B%20DROP&q=%2B-*()');

    expect($filters->sort)->toBeNull()
        ->and($filters->term)->toBeNull()
        ->and($filters->ignored)->toBeFalse();
});

it('decide el orden que se aplica', function (string $query, SearchMode $mode, CatalogSort $expected): void {
    expect(filtersFrom($query)->effectiveSort($mode))->toBe($expected);
})->with([
    'sin nada' => ['', SearchMode::Like, CatalogSort::Curated],
    'con término y el índice' => ['q=taladro', SearchMode::FullText, CatalogSort::Relevance],
    'con término y LIKE' => ['q=taladro', SearchMode::Like, CatalogSort::Curated],
    'con término y orden explícito' => ['q=taladro&sort=price_asc', SearchMode::FullText, CatalogSort::PriceAsc],
    'con el curado elegido a propósito' => ['q=taladro&sort=curated', SearchMode::FullText, CatalogSort::Curated],
    'con un orden inválido y término' => ['q=taladro&sort=popular', SearchMode::FullText, CatalogSort::Relevance],
]);
