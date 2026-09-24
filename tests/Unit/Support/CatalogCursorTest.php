<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogSort;
use App\Support\CatalogCursor;
use Illuminate\Pagination\Cursor;

/**
 * El cursor de la parrilla pública (`REQ-CAT-021` RB-6, SP-9).
 *
 * Solo acepta la forma exacta que emite la parrilla **para ese orden**
 * (`CatalogSort::keyset()`, `T-034`); todo lo demás es la primera página.
 */
function encodedCursor(mixed $parameters): string
{
    return rtrim(strtr(base64_encode((string) json_encode($parameters)), '+/', '-_'), '=');
}

it('lee el cursor que la propia parrilla emitió', function (): void {
    $emitted = (new Cursor(['catalog_position' => 12, 'products.id' => 40], true))->encode();

    $cursor = CatalogCursor::from($emitted, CatalogSort::Curated);

    expect($cursor)->toBeInstanceOf(Cursor::class)
        ->and($cursor?->parameter('catalog_position'))->toBe(12)
        ->and($cursor?->parameter('products.id'))->toBe(40)
        ->and($cursor?->pointsToNextItems())->toBeTrue();
});

it('acepta las cifras como texto, que es como a veces las devuelve la base', function (): void {
    $cursor = CatalogCursor::from(encodedCursor([
        'catalog_position' => '3',
        'products.id' => '9',
        '_pointsToNextItems' => false,
    ]), CatalogSort::Curated);

    expect($cursor?->parameter('products.id'))->toBe(9)
        ->and($cursor?->pointsToPreviousItems())->toBeTrue();
});

it('lee el cursor de cada orden por columnas con sus tipos', function (CatalogSort $sort, array $parameters): void {
    $cursor = CatalogCursor::from((new Cursor($parameters))->encode(), $sort);

    expect($cursor)->toBeInstanceOf(Cursor::class)
        ->and($cursor?->toArray())->toBe([...$parameters, '_pointsToNextItems' => true]);
})->with([
    'recientes' => [CatalogSort::Recent, ['catalog_added_at' => '2026-09-24 10:15:00', 'products.id' => 7]],
    'precio ascendente' => [CatalogSort::PriceAsc, ['sort_price' => 25000, 'products.id' => 7]],
    'precio descendente' => [CatalogSort::PriceDesc, ['sort_price' => 0, 'products.id' => 7]],
    'nombre, con tildes' => [CatalogSort::Name, ['products.name' => 'Cepillo eléctrico', 'products.id' => 7]],
]);

/**
 * El cursor de un orden no vale para otro: cambiar el orden con un lote ya
 * cargado no puede continuar «a partir de» una fila de otra consulta.
 */
it('rechaza el cursor de otro orden', function (CatalogSort $emittedFor, CatalogSort $readAs): void {
    $parameters = array_map(fn (): int => 1, $emittedFor->keyset() ?? [CatalogCursor::OFFSET => 1]);

    expect(CatalogCursor::from((new Cursor($parameters))->encode(), $readAs))->toBeNull();
})->with([
    'curado leído como precio' => [CatalogSort::Curated, CatalogSort::PriceAsc],
    'precio leído como nombre' => [CatalogSort::PriceAsc, CatalogSort::Name],
    'relevancia leída como curado' => [CatalogSort::Relevance, CatalogSort::Curated],
    'curado leído como relevancia' => [CatalogSort::Curated, CatalogSort::Relevance],
]);

it('devuelve la primera página ante cualquier otra cosa', function (mixed $encoded, CatalogSort $sort = CatalogSort::Curated): void {
    expect(CatalogCursor::from($encoded, $sort))->toBeNull();
})->with([
    'nada' => [null],
    'vacío' => [''],
    'un array de la query string' => [['a' => 'b']],
    'basura' => ['!!!no-es-base64!!!'],
    'JSON escalar' => [encodedCursor(5)],
    'sin dirección' => [encodedCursor(['catalog_position' => 1, 'products.id' => 1])],
    'dirección que no es booleana' => [encodedCursor(['catalog_position' => 1, 'products.id' => 1, '_pointsToNextItems' => 'yes'])],
    'la forma de T-033' => [encodedCursor(['catalog_product.position' => 1, 'products.id' => 1, '_pointsToNextItems' => true])],
    'columnas en otro orden' => [encodedCursor(['products.id' => 1, 'catalog_position' => 1, '_pointsToNextItems' => true])],
    'una clave de más' => [encodedCursor(['catalog_position' => 1, 'products.id' => 1, 'x' => 1, '_pointsToNextItems' => true])],
    'posición inyectada' => [encodedCursor(['catalog_position' => '1 or 1=1', 'products.id' => 1, '_pointsToNextItems' => true])],
    'negativo' => [encodedCursor(['catalog_position' => -1, 'products.id' => 1, '_pointsToNextItems' => true])],
    'demasiado largo' => [str_repeat('a', 3000)],
    'fecha inyectada' => [encodedCursor(['catalog_added_at' => "2026-01-01' or '1", 'products.id' => 1, '_pointsToNextItems' => true]), CatalogSort::Recent],
    'precio con decimales' => [encodedCursor(['sort_price' => 10.5, 'products.id' => 1, '_pointsToNextItems' => true]), CatalogSort::PriceAsc],
    'nombre vacío' => [encodedCursor(['products.name' => '', 'products.id' => 1, '_pointsToNextItems' => true]), CatalogSort::Name],
    'nombre más largo que la columna' => [encodedCursor(['products.name' => str_repeat('a', 201), 'products.id' => 1, '_pointsToNextItems' => true]), CatalogSort::Name],
]);

describe('la relevancia, por desplazamiento', function (): void {
    it('lee el desplazamiento que emitió', function (): void {
        $cursor = CatalogCursor::from(CatalogCursor::encodeOffset(24), CatalogSort::Relevance);

        expect(CatalogCursor::offsetOf($cursor))->toBe(24);
    });

    it('sin cursor empieza por el principio', function (): void {
        expect(CatalogCursor::offsetOf(null))->toBe(0);
    });

    it('rechaza lo que no emitió', function (mixed $parameters): void {
        expect(CatalogCursor::from(encodedCursor($parameters), CatalogSort::Relevance))->toBeNull();
    })->with([
        'hacia atrás' => [['offset' => 12, '_pointsToNextItems' => false]],
        'cero' => [['offset' => 0, '_pointsToNextItems' => true]],
        'negativo' => [['offset' => -12, '_pointsToNextItems' => true]],
        'como texto' => [['offset' => '12', '_pointsToNextItems' => true]],
        'más allá del techo' => [['offset' => CatalogCursor::MAX_OFFSET + 1, '_pointsToNextItems' => true]],
        'una clave de más' => [['offset' => 12, 'x' => 1, '_pointsToNextItems' => true]],
    ]);
});

it('pide la primera página sin que el paginador vuelva a leer la petición', function (): void {
    expect(Cursor::fromEncoded(CatalogCursor::FIRST_PAGE))->toBeNull();
});
