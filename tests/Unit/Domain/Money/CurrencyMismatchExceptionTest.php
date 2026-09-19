<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;

/**
 * RB-3: no hay conversión implícita en ninguna parte.
 *
 * El mensaje importa tanto como el lanzamiento. Quien se encuentra esto suele
 * tener una cadena de cuentas encadenadas, y saber que chocaron USD y VES no
 * le dice cuál de ellas lo hizo.
 */
it('nombra las dos monedas y la operación', function (): void {
    $exception = CurrencyMismatchException::between(Currency::USD, Currency::VES, 'sumar');

    expect($exception->getMessage())
        ->toContain('sumar')
        ->toContain('USD')
        ->toContain('VES');
});

/**
 * Y manda a la salida correcta: convertir explícitamente, diciendo con qué
 * tasa —que es el dato que un `add()` silencioso se estaría inventando—.
 */
it('manda a convertTo() en vez de dejar la cuenta a medias', function (): void {
    expect(CurrencyMismatchException::between(Currency::VES, Currency::USD, 'restar')->getMessage())
        ->toContain('Money::convertTo()');
});

/**
 * La otra cara: toda columna `*_cents` es de centavos de **USD** (RB-1), así
 * que persistir ahí un importe en bolívares produce una fila indistinguible de
 * una correcta. El mensaje nombra la columna porque el error se descubre
 * leyendo un saldo, lejos de donde se escribió.
 */
it('nombra la columna cuando el choque es al persistir', function (): void {
    $exception = CurrencyMismatchException::whilePersisting('products.price_cents', Currency::VES, Currency::USD);

    expect($exception->getMessage())
        ->toContain('products.price_cents')
        ->toContain('VES')
        ->toContain('USD');
});

it('es una excepción de ejecución, no de validación', function (): void {
    expect(CurrencyMismatchException::between(Currency::USD, Currency::VES, 'sumar'))
        ->toBeInstanceOf(RuntimeException::class);
});
