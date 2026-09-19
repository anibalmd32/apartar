<?php

declare(strict_types=1);

use App\Domain\Catalog\ProductStatus;

/**
 * El enum de estados de `REQ-CAT-007` RB-1.
 *
 * Lo que se fija aquí es **hasta dónde llega** el enum: responde por el estado
 * del producto y por nada más. La visibilidad real es la intersección con la
 * accesibilidad del catálogo (RB-6), y esa la cruza la consulta pública.
 */
it('declara los tres estados de los contratos compartidos', function (): void {
    $values = array_map(fn (ProductStatus $status): string => $status->value, ProductStatus::cases());

    expect($values)->toBe(['draft', 'published', 'archived']);
});

/**
 * RB-1 · `draft` es invisible y sin URL alcanzable; `archived` es invisible
 * pero conserva historial. Solo `published` se renderiza.
 */
it('solo deja ver el producto publicado', function (ProductStatus $status, bool $visible): void {
    expect($status->isVisibleInCatalog())->toBe($visible);
})->with([
    'borrador' => [ProductStatus::Draft, false],
    'publicado' => [ProductStatus::Published, true],
    'archivado' => [ProductStatus::Archived, false],
]);

/**
 * RB-1 y SP-2 · sobre un archivado no se aparta **nunca**, ni con el
 * formulario ya abierto.
 */
it('solo deja apartar el producto publicado', function (ProductStatus $status, bool $reservable): void {
    expect($status->canBeReserved())->toBe($reservable);
})->with([
    'borrador' => [ProductStatus::Draft, false],
    'publicado' => [ProductStatus::Published, true],
    'archivado' => [ProductStatus::Archived, false],
]);

/**
 * El enum no mira el reloj ni el inventario, y eso es una decisión, no una
 * omisión: `published_at` futuro se evalúa en la consulta (RB-5) y `stock = 0`
 * no cambia el estado (RB-4). Si algún día alguien metiera cualquiera de las
 * dos cosas aquí, este test es el que lo diría — el enum no recibe ni la fecha
 * ni las existencias, así que no podría contestarlas.
 */
it('contesta solo por el estado, sin fecha ni existencias', function (): void {
    $reflection = new ReflectionEnum(ProductStatus::class);

    foreach (['isVisibleInCatalog', 'canBeReserved'] as $method) {
        expect($reflection->getMethod($method)->getNumberOfParameters())->toBe(0);
    }
});
