<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;

it('nombra las dos monedas y la operación', function (): void {
    $exception = CurrencyMismatchException::between(Currency::USD, Currency::VES, 'sumar');

    expect($exception->getMessage())
        ->toContain('sumar')
        ->toContain('USD')
        ->toContain('VES');
});

it('manda a convertTo() en vez de dejar la cuenta a medias', function (): void {
    expect(CurrencyMismatchException::between(Currency::VES, Currency::USD, 'restar')->getMessage())
        ->toContain('Money::convertTo()');
});

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
