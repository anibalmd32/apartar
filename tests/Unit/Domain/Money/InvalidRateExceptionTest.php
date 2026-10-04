<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Money\InvalidRateException;

/**
 * SP-4: tasa `0` o negativa, **antes** de convertir.
 *
 * El motivo de que sea antes: una tasa `0` no revienta `bcmul`, devuelve `0`.
 * Un precio de cero bolívares se pinta igual de bien que uno correcto, y el
 * error solo se descubre cuando alguien intenta cobrarlo.
 */
it('explica por qué una tasa no positiva no sirve', function (): void {
    $exception = InvalidRateException::notPositive('0');

    expect($exception->getMessage())
        ->toContain('"0"')
        ->toContain('no es mayor que cero')
        ->toContain('importe de 0');
});

it('recoge también la tasa negativa', function (): void {
    expect(InvalidRateException::notPositive('-42.5')->getMessage())->toContain('"-42.5"');
});

/**
 * `RateSnapshot::rate` es una cadena libre —lo es para que nunca pase por
 * `float`—, así que nada impide que llegue algo que no es un número. Tiene su
 * propio constructor porque el arreglo es otro: `notPositive()` apunta a la
 * fuente de la tasa; esto, a quien la parseó.
 */
it('distingue la tasa mal formada de la tasa no positiva', function (): void {
    $malformed = InvalidRateException::malformed('cuarenta y dos');

    expect($malformed->getMessage())
        ->toContain('no es un decimal válido')
        ->toContain('parser');

    expect($malformed->getMessage())->not->toContain('mayor que cero');
});

/**
 * **Tres excepciones para tres problemas distintos.** «No hay tasa» es
 * `MissingExchangeRateException` y bloquea la operación; «la fuente no
 * respondió» es `ExchangeRateUnavailableException` y se reintenta; esto es
 * «sí hay tasa y la cifra no sirve», que no se arregla ni esperando ni
 * fijando otra fuente. Ninguna es instancia de otra, así que capturar una no
 * captura las demás por descuido.
 */
it('no se confunde con las dos excepciones de la tasa', function (): void {
    $exception = InvalidRateException::notPositive('0');

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception)->not->toBeInstanceOf(MissingExchangeRateException::class);
    expect($exception)->not->toBeInstanceOf(ExchangeRateUnavailableException::class);
});

/**
 * `T-055`: una tasa escrita a mano con más decimales de los que se guardan no
 * es un fallo de la fuente, y el mensaje no la manda a revisar un parser.
 */
it('explica que una tasa manual tiene más decimales de los que se guardan', function (): void {
    expect(InvalidRateException::tooPrecise('42.123456789', 8)->getMessage())
        ->toBe('La tasa "42.123456789" tiene más de 8 decimales, que es lo que se guarda de ella.');
});

it('explica que una tasa manual tiene más cifras enteras de las que caben', function (): void {
    expect(InvalidRateException::tooLarge('12345678901', 10)->getMessage())
        ->toBe('La tasa "12345678901" tiene más de 10 cifras enteras, que es lo que cabe de ella.');
});
