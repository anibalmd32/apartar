<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogFilters;
use App\Domain\Catalog\CatalogSort;
use App\Domain\Catalog\SearchMode;
use Illuminate\Http\Request;

/**
 * Los filtros de la query string, normalizados (`REQ-CAT-006` RB-4 a RB-6,
 * SP-1, SP-3 a SP-5; `REQ-CAT-002` RB-4, SP-1).
 *
 * El fondo —que la categoría esté en el catálogo— se prueba contra la base en
 * `CatalogSearchAndFiltersTest`; aquí, la forma y las reglas.
 */
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

/** SP-1, SP-4, SP-5 · lo que el panel no ofrece se quita, y se dice. */
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

/** SP-3 y `REQ-CAT-002` SP-1 · orden y término inválidos se ignoran sin aviso. */
it('ignora en silencio el orden inválido y el término que se queda en nada', function (): void {
    $filters = filtersFrom('sort=price_asc%3B%20DROP&q=%2B-*()');

    expect($filters->sort)->toBeNull()
        ->and($filters->term)->toBeNull()
        ->and($filters->ignored)->toBeFalse();
});

/**
 * `REQ-CAT-002` RB-4 · el orden explícito manda; sin él, relevancia solo si
 * el índice contestó, y el curado en cualquier otro caso.
 */
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
