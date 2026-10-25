<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogNotPublishableException;
use App\Models\Catalog;

function unpublishableCatalog(string $name = 'Ofertas de septiembre'): Catalog
{
    return (new Catalog)->forceFill(['name' => $name]);
}

it('nombra de una vez todo lo que falta', function (): void {
    $exception = CatalogNotPublishableException::of(unpublishableCatalog(), [
        'products' => 'Añade al menos un producto publicado.',
        'payment_accounts' => 'Activa al menos una cuenta de cobro.',
        'owner_email' => 'Falta verificar el correo del dueño.',
    ]);

    expect($exception->getMessage())
        ->toContain('«Ofertas de septiembre»')
        ->toContain('al menos un producto publicado dentro, una cuenta de cobro activa y verificar el correo del dueño');
});

it('con un solo pendiente no enumera', function (): void {
    $exception = CatalogNotPublishableException::of(unpublishableCatalog(), [
        'expires_at' => 'La fecha ya pasó.',
    ]);

    expect($exception->getMessage())
        ->toContain('Pendiente: una fecha de caducidad que no haya pasado.')
        ->not->toContain(' y ');
});

it('conserva el mapa de campo a corrección', function (): void {
    $missing = [
        'products' => 'Añade al menos un producto publicado.',
        'payment_accounts' => 'Activa al menos una cuenta de cobro.',
    ];

    $exception = CatalogNotPublishableException::of(unpublishableCatalog(), $missing);

    expect($exception->missing)->toBe($missing);
    expect($exception->catalog->name)->toBe('Ofertas de septiembre');
});

it('responde 422, que es lo que significa «te falta algo tuyo»', function (): void {
    expect(CatalogNotPublishableException::STATUS)->toBe(422);
});
