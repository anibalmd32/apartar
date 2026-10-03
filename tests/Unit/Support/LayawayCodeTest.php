<?php

declare(strict_types=1);

use App\Support\LayawayCode;

/**
 * `REQ-IDN-008` SP-7 y `REQ-LAY-001` RB-9: el código como lo escribe una
 * persona, llevado a la forma canónica.
 */
it('normaliza mayúsculas, separadores y el prefijo omitido', function (string $typed): void {
    expect(LayawayCode::normalize($typed))->toBe('AP-7K3M9QRT')
        ->and(LayawayCode::isWellFormed(LayawayCode::normalize($typed)))->toBeTrue();
})->with([
    'canónico' => ['AP-7K3M9QRT'],
    'minúsculas' => ['ap-7k3m9qrt'],
    'sin guion' => ['AP7K3M9QRT'],
    'con espacios y guiones de más' => [' ap 7k3m-9qrt '],
    'sin prefijo' => ['7k3m9qrt'],
]);

/**
 * No corrige caracteres: quien escribe un ambiguo ha escrito un código que no
 * existe, y no pasa la forma.
 */
it('no da por bueno un código con caracteres ambiguos ni con otra longitud', function (string $typed): void {
    expect(LayawayCode::isWellFormed(LayawayCode::normalize($typed)))->toBeFalse();
})->with([
    'con cero' => ['AP-7K3M0QRT'],
    'con o' => ['AP-7K3MOQRT'],
    'corto' => ['AP-7K3M9QR'],
    'vacío' => [''],
]);

it('genera códigos de un alfabeto sin caracteres ambiguos', function (): void {
    expect(LayawayCode::ALPHABET)->not->toContain('0', 'O', '1', 'I')
        ->and(LayawayCode::isWellFormed(LayawayCode::generate()))->toBeTrue();
});
