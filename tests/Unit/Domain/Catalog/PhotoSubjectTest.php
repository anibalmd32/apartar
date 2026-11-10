<?php

declare(strict_types=1);

use App\Domain\Catalog\PhotoSubject;

it('lee la respuesta de la IA sin importar mayúsculas ni espacios', function (): void {
    expect(PhotoSubject::fromAnswer(' Animal '))->toBe(PhotoSubject::Animal)
        ->and(PhotoSubject::fromAnswer('person'))->toBe(PhotoSubject::Person);
});

it('ante una respuesta desconocida o ausente supone que es un producto', function (): void {
    expect(PhotoSubject::fromAnswer('planta'))->toBe(PhotoSubject::Product)
        ->and(PhotoSubject::fromAnswer(null))->toBe(PhotoSubject::Product)
        ->and(PhotoSubject::fromAnswer(['animal']))->toBe(PhotoSubject::Product);
});

it('solo lo que no es un producto necesita revisión', function (): void {
    expect(PhotoSubject::Product->needsReview())->toBeFalse()
        ->and(PhotoSubject::Animal->needsReview())->toBeTrue()
        ->and(PhotoSubject::Person->needsReview())->toBeTrue()
        ->and(PhotoSubject::Other->needsReview())->toBeTrue();
});

it('nombra lo que se ve en las fotos para la pregunta al negocio', function (): void {
    expect(PhotoSubject::Animal->label())->toBe('un animal')
        ->and(PhotoSubject::Person->label())->toBe('una persona')
        ->and(PhotoSubject::Other->label())->toBe('algo que no parece un producto')
        ->and(PhotoSubject::Product->label())->toBe('un producto');
});
