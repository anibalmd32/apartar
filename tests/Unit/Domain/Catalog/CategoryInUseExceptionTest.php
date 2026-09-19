<?php

declare(strict_types=1);

use App\Domain\Catalog\CategoryInUseException;
use App\Models\Category;

/**
 * El rechazo de `REQ-BOF-023` SP-1 y SP-3.
 *
 * Dos operaciones bloqueadas por el mismo hecho —la categoría tiene productos
 * dentro— y un solo tipo para las dos. Lo que se fija aquí es que cada
 * constructor diga **lo suyo**: el del borrado nombra la salida y el del
 * enlace aclara que el nombre no está bloqueado, que es la mitad del requisito
 * que se pierde si el mensaje solo dice «no puedes».
 */
function inUseCategory(string $name = 'Ropa'): Category
{
    return (new Category)->forceFill(['name' => $name]);
}

/**
 * SP-1 · el conteo va dentro del mensaje porque es lo que convierte un «no
 * puedes» en una tarea con tamaño conocido: reasignar tres productos es un
 * minuto, y sin la cifra quien lo lee no sabe si le espera eso o media tarde.
 */
it('dice cuántos productos hay y cuál es la salida', function (): void {
    $exception = CategoryInUseException::cannotBeDeleted(inUseCategory(), 3);

    expect($exception->getMessage())
        ->toContain('«Ropa»')
        ->toContain('3 productos')
        ->toContain('Reasígnalos');

    expect($exception->productsCount)->toBe(3);
});

/**
 * Y concuerda en singular, que es el caso más frecuente: «tiene 1 productos
 * asociados» delata que nadie leyó nunca este mensaje.
 */
it('concuerda el mensaje del borrado en singular', function (): void {
    expect(CategoryInUseException::cannotBeDeleted(inUseCategory(), 1)->getMessage())
        ->toContain('1 producto asociado')
        ->not->toContain('1 productos');
});

/**
 * SP-3 · el del enlace tiene que decir **las dos cosas**: que el enlace está
 * cerrado y que el nombre no. El guardado es todo o nada, así que quien mandó
 * las dos cosas a la vez no ha guardado ninguna y necesita saber que
 * reintentar sin tocar el enlace funciona.
 */
it('explica que el enlace se cierra pero el nombre no', function (): void {
    $exception = CategoryInUseException::slugIsLocked(inUseCategory('Electrodomésticos'), 2);

    expect($exception->getMessage())
        ->toContain('El enlace')
        ->toContain('2 productos publicados')
        ->toContain('El nombre sí');

    expect($exception->productsCount)->toBe(2);
});

/**
 * El campo al que va el error distingue a los dos, y es lo que permite al
 * controlador ponerlo donde se corrige: el del borrado en el desplegable de la
 * reasignación, el del enlace en su campo.
 */
it('nombra el campo al que pertenece cada rechazo', function (): void {
    expect(CategoryInUseException::cannotBeDeleted(inUseCategory(), 1)->field)->toBe('category');
    expect(CategoryInUseException::slugIsLocked(inUseCategory(), 1)->field)->toBe('slug');
});

/**
 * `422` y no `403`: la petición está bien formada y quien la hace tiene
 * permiso de sobra. Lo que no se puede es aceptarla.
 */
it('se responde con 422', function (): void {
    expect(CategoryInUseException::STATUS)->toBe(422);
});
