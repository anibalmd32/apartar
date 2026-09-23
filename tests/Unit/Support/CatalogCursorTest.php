<?php

declare(strict_types=1);

use App\Support\CatalogCursor;
use Illuminate\Pagination\Cursor;

/**
 * El cursor de la parrilla pública (`REQ-CAT-021` RB-6, SP-9).
 *
 * Solo acepta la forma exacta que emite la parrilla; todo lo demás es la
 * primera página.
 */
function encodedCursor(mixed $parameters): string
{
    return rtrim(strtr(base64_encode((string) json_encode($parameters)), '+/', '-_'), '=');
}

it('lee el cursor que la propia parrilla emitió', function (): void {
    $emitted = (new Cursor(['catalog_product.position' => 12, 'products.id' => 40], true))->encode();

    $cursor = CatalogCursor::from($emitted);

    expect($cursor)->toBeInstanceOf(Cursor::class)
        ->and($cursor?->parameter('catalog_product.position'))->toBe(12)
        ->and($cursor?->parameter('products.id'))->toBe(40)
        ->and($cursor?->pointsToNextItems())->toBeTrue();
});

it('acepta las cifras como texto, que es como a veces las devuelve la base', function (): void {
    $cursor = CatalogCursor::from(encodedCursor([
        'catalog_product.position' => '3',
        'products.id' => '9',
        '_pointsToNextItems' => false,
    ]));

    expect($cursor?->parameter('products.id'))->toBe(9)
        ->and($cursor?->pointsToPreviousItems())->toBeTrue();
});

it('devuelve la primera página ante cualquier otra cosa', function (mixed $encoded): void {
    expect(CatalogCursor::from($encoded))->toBeNull();
})->with([
    'nada' => [null],
    'vacío' => [''],
    'un array de la query string' => [['a' => 'b']],
    'basura' => ['!!!no-es-base64!!!'],
    'JSON escalar' => [encodedCursor(5)],
    'sin dirección' => [encodedCursor(['catalog_product.position' => 1, 'products.id' => 1])],
    'dirección que no es booleana' => [encodedCursor(['catalog_product.position' => 1, 'products.id' => 1, '_pointsToNextItems' => 'yes'])],
    'claves de otro orden' => [encodedCursor(['products.price_cents' => 1, '_pointsToNextItems' => true])],
    'columnas en otro orden' => [encodedCursor(['products.id' => 1, 'catalog_product.position' => 1, '_pointsToNextItems' => true])],
    'una clave de más' => [encodedCursor(['catalog_product.position' => 1, 'products.id' => 1, 'x' => 1, '_pointsToNextItems' => true])],
    'posición inyectada' => [encodedCursor(['catalog_product.position' => '1 or 1=1', 'products.id' => 1, '_pointsToNextItems' => true])],
    'negativo' => [encodedCursor(['catalog_product.position' => -1, 'products.id' => 1, '_pointsToNextItems' => true])],
    'demasiado largo' => [str_repeat('a', 300)],
]);

it('pide la primera página sin que el paginador vuelva a leer la petición', function (): void {
    expect(Cursor::fromEncoded(CatalogCursor::FIRST_PAGE))->toBeNull();
});
