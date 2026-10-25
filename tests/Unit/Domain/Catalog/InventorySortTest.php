<?php

declare(strict_types=1);

use App\Domain\Catalog\InventorySort;

it('dice qué columna cualificada ordena cada caso', function (InventorySort $sort, string $column): void {
    expect($sort->column())->toBe($column);
})->with([
    [InventorySort::Created, 'products.id'],
    [InventorySort::Name, 'products.name'],
    [InventorySort::Sku, 'products.sku'],
    [InventorySort::Price, 'products.price_cents'],
    [InventorySort::Stock, 'products.stock'],
    [InventorySort::Updated, 'products.updated_at'],
]);

it('acepta de la URL cada caso por su valor, y nada más', function (): void {
    $whitelist = InventorySort::whitelist();

    foreach (InventorySort::cases() as $sort) {
        expect($whitelist->resolve($sort->value))->toBe($sort);
    }

    expect($whitelist->resolve('price_cents'))->toBeNull()
        ->and($whitelist->resolve('products.name'))->toBeNull()
        ->and($whitelist->resolve('-name'))->toBeNull();
});
