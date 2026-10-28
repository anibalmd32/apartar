<?php

declare(strict_types=1);

use App\Domain\Catalog\IncompleteProductException;
use App\Models\Product;

function incompleteProduct(string $name = 'Taladro Bosch'): Product
{
    return (new Product)->forceFill(['name' => $name]);
}

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

it('no compone una lista cuando falta una sola cosa', function (): void {
    expect(IncompleteProductException::of(incompleteProduct(), ['images' => 'Sube una foto.'])->getMessage())
        ->toContain('Pendiente: al menos una foto. Complétalo');
});

it('resume las condiciones de pago en un solo rótulo sin perder el detalle', function (): void {
    $exception = IncompleteProductException::of(incompleteProduct(), [
        'down_payment' => 'La cuota inicial tiene que ser menor que el precio.',
        'installments_count' => 'El número de cuotas tiene que estar entre 1 y 36.',
        'frequency_days' => 'Los días entre cuotas tienen que estar entre 1 y 90.',
    ]);

    expect($exception->getMessage())
        ->toContain('Pendiente: las condiciones de pago.')
        ->not->toContain('las condiciones de pago, las condiciones de pago');

    expect(array_keys($exception->missing))
        ->toBe(['down_payment', 'installments_count', 'frequency_days']);

    expect($exception->missing['installments_count'])->toContain('entre 1 y 36');
});

it('conserva el producto y el mapa de campos', function (): void {
    $product = incompleteProduct('Sierra');

    $exception = IncompleteProductException::of($product, ['category_id' => 'Elige una categoría.']);

    expect($exception->product)->toBe($product);
    expect($exception->missing)->toBe(['category_id' => 'Elige una categoría.']);
    expect(IncompleteProductException::STATUS)->toBe(422);
});

it('resume lo que falta sin la frase completa', function (): void {
    $exception = IncompleteProductException::of(incompleteProduct(), [
        'images' => 'Sube al menos una foto.',
        'description' => 'Escribe la descripción.',
    ]);

    expect($exception->missingSummary())->toBe('al menos una foto y la descripción');
});

it('enumera con comas y une el último con «y»', function (): void {
    expect(IncompleteProductException::listOf(['a', 'b', 'c']))->toBe('a, b y c');
    expect(IncompleteProductException::listOf(['a']))->toBe('a');
    expect(IncompleteProductException::listOf([]))->toBe('');
});
