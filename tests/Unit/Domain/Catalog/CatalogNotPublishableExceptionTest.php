<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogNotPublishableException;
use App\Models\Catalog;

/**
 * El rechazo de `REQ-CAT-022` RB-4, que es un `422` **con la lista de lo que
 * falta**.
 *
 * Misma forma que `IncompleteProductException` y por el mismo motivo: quien
 * pulsa «Publicar» con el catálogo vacío y sin cuenta de cobro va a arreglar
 * las dos de una pasada, y un mensaje que nombre solo la primera lo manda a
 * buscar dos veces. Quien evalúa los requisitos es `PublishCatalogAction`;
 * esta clase solo los transporta y los redacta.
 */
function unpublishableCatalog(string $name = 'Ofertas de septiembre'): Catalog
{
    return (new Catalog)->forceFill(['name' => $name]);
}

/**
 * RB-4 · el resumen los nombra todos, con la concordancia que hace que se lea
 * como una frase y no como un volcado.
 */
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

/**
 * Con un solo pendiente no hay enumeración que hacer: la frase se lee entera
 * sin comas sueltas ni una «y» colgando.
 */
it('con un solo pendiente no enumera', function (): void {
    $exception = CatalogNotPublishableException::of(unpublishableCatalog(), [
        'expires_at' => 'La fecha ya pasó.',
    ]);

    expect($exception->getMessage())
        ->toContain('Pendiente: una fecha de caducidad que no haya pasado.')
        ->not->toContain(' y ');
});

/**
 * El detalle por campo viaja aparte del resumen, que es lo que permite a la
 * pantalla poner cada frase junto a su control.
 */
it('conserva el mapa de campo a corrección', function (): void {
    $missing = [
        'products' => 'Añade al menos un producto publicado.',
        'payment_accounts' => 'Activa al menos una cuenta de cobro.',
    ];

    $exception = CatalogNotPublishableException::of(unpublishableCatalog(), $missing);

    expect($exception->missing)->toBe($missing);
    expect($exception->catalog->name)->toBe('Ofertas de septiembre');
});

/**
 * `422` y no `403`: la petición está bien formada y quien la hace tiene
 * permiso. Lo que falta son datos suyos. La suspensión es el otro caso y
 * tiene su propia excepción con su `403`.
 */
it('responde 422, que es lo que significa «te falta algo tuyo»', function (): void {
    expect(CatalogNotPublishableException::STATUS)->toBe(422);
});
