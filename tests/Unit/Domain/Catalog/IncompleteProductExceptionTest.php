<?php

declare(strict_types=1);

use App\Domain\Catalog\IncompleteProductException;
use App\Models\Product;

/**
 * El rechazo de `REQ-BOF-020` SP-3, que es un `422` **con la lista de lo que
 * falta**.
 *
 * Lo que se fija aquí es la redacción, que es donde el requisito vive o muere:
 * quien pulsa «Publicar» con un producto sin foto y sin categoría va a
 * arreglar las dos de una pasada, y un mensaje que nombre solo la primera lo
 * manda a buscar dos veces. Las cuatro condiciones las evalúa
 * `ChangeProductStatusAction`; esta clase solo las transporta y las cuenta.
 */
function incompleteProduct(string $name = 'Taladro Bosch'): Product
{
    return (new Product)->forceFill(['name' => $name]);
}

/**
 * SP-3 · la lista entera en el resumen, con la concordancia que hace que se
 * lea como una frase y no como un volcado.
 */
it('nombra de una vez todo lo que falta', function (): void {
    $exception = IncompleteProductException::of(incompleteProduct(), [
        'images' => 'Sube al menos una foto.',
        'category_id' => 'Elige una categoría.',
        'description' => 'Escribe la descripción.',
    ]);

    expect($exception->getMessage())
        ->toContain('«Taladro Bosch»')
        ->toContain('al menos una foto, una categoría y la descripción')
        ->toContain('Complétalo');
});

/**
 * Con un solo pendiente no aparece la coma ni la «y»: una lista de un elemento
 * escrita como lista delata que nadie leyó nunca este mensaje.
 */
it('no compone una lista cuando falta una sola cosa', function (): void {
    expect(IncompleteProductException::of(incompleteProduct(), ['images' => 'Sube una foto.'])->getMessage())
        ->toContain('Pendiente: al menos una foto. Complétalo');
});

/**
 * **Las seis reglas de coherencia comparten un solo rótulo.**
 *
 * Son un solo pendiente —«las condiciones de pago»— y enumerarlas por separado
 * daría una lista de cuatro entradas para un formulario que se arregla mirando
 * un bloque. El detalle de cada una sigue viajando en `$missing`, junto a su
 * campo, que es donde la pantalla lo pone.
 */
it('resume las condiciones de pago en un solo rótulo sin perder el detalle', function (): void {
    $exception = IncompleteProductException::of(incompleteProduct(), [
        'down_payment' => 'La cuota inicial tiene que ser menor que el precio.',
        'installments_count' => 'El número de cuotas tiene que estar entre 1 y 36.',
        'frequency_days' => 'Los días entre cuotas tienen que estar entre 1 y 90.',
    ]);

    expect($exception->getMessage())
        ->toContain('Pendiente: las condiciones de pago.')
        // Una sola vez, aunque sean tres campos.
        ->not->toContain('las condiciones de pago, las condiciones de pago');

    expect(array_keys($exception->missing))
        ->toBe(['down_payment', 'installments_count', 'frequency_days']);

    expect($exception->missing['installments_count'])->toContain('entre 1 y 36');
});

/**
 * Y el mapa por campo llega intacto: es lo que permite que cada frase aterrice
 * junto al control que hay que corregir en vez de todas sobre el mismo.
 */
it('conserva el producto y el mapa de campos', function (): void {
    $product = incompleteProduct('Sierra');

    $exception = IncompleteProductException::of($product, ['category_id' => 'Elige una categoría.']);

    expect($exception->product)->toBe($product);
    expect($exception->missing)->toBe(['category_id' => 'Elige una categoría.']);
    expect(IncompleteProductException::STATUS)->toBe(422);
});
