<?php

declare(strict_types=1);

use App\Domain\Catalog\CategoryInUseException;
use App\Models\Category;

function inUseCategory(string $name = 'Ropa'): Category
{
    return (new Category)->forceFill(['name' => $name]);
}

it('dice cuántos productos hay y cuál es la salida', function (): void {
    $exception = CategoryInUseException::cannotBeDeleted(inUseCategory(), 3);

    expect($exception->getMessage())
        ->toContain('«Ropa»')
        ->toContain('3 productos')
        ->toContain('Reasígnalos');

    expect($exception->productsCount)->toBe(3);
});

it('concuerda el mensaje del borrado en singular', function (): void {
    expect(CategoryInUseException::cannotBeDeleted(inUseCategory(), 1)->getMessage())
        ->toContain('1 producto asociado')
        ->not->toContain('1 productos');
});

it('explica que el enlace se cierra pero el nombre no', function (): void {
    $exception = CategoryInUseException::slugIsLocked(inUseCategory('Electrodomésticos'), 2);

    expect($exception->getMessage())
        ->toContain('El enlace')
        ->toContain('2 productos publicados')
        ->toContain('El nombre sí');

    expect($exception->productsCount)->toBe(2);
});

it('nombra el campo al que pertenece cada rechazo', function (): void {
    expect(CategoryInUseException::cannotBeDeleted(inUseCategory(), 1)->field)->toBe('category');
    expect(CategoryInUseException::slugIsLocked(inUseCategory(), 1)->field)->toBe('slug');
});

it('se responde con 422', function (): void {
    expect(CategoryInUseException::STATUS)->toBe(422);
});
