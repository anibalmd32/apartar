<?php

declare(strict_types=1);

use App\Domain\Exchange\ManualRate;
use App\Domain\Money\InvalidRateException;

it('acepta un decimal positivo que cabe en la columna', function (string $rate): void {
    expect((new ManualRate($rate))->rate)->toBe($rate);
})->with([
    'con un decimal' => ['42.5'],
    'con dos' => ['42.50'],
    'ocho decimales, el borde' => ['36.12345678'],
    'entero' => ['1'],
    'la más pequeña' => ['0.00000001'],
    'diez cifras enteras, el borde' => ['9999999999.99999999'],
    'ceros a la izquierda, que no ocupan sitio' => ['000000000042.5'],
]);

it('rechaza lo que no se puede usar o no se guardaría tal cual', function (string $rate): void {
    new ManualRate($rate);
})->throws(InvalidRateException::class)->with([
    'cero' => ['0'],
    'negativa' => ['-42.5'],
    'texto' => ['cuarenta'],
    'coma decimal' => ['42,50'],
    'exponencial' => ['4.25e1'],
    'nueve decimales' => ['42.123456789'],
    'once cifras enteras' => ['12345678901'],
]);
