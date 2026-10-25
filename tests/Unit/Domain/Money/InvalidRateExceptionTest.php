<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Money\InvalidRateException;

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

it('distingue la tasa mal formada de la tasa no positiva', function (): void {
    $malformed = InvalidRateException::malformed('cuarenta y dos');

    expect($malformed->getMessage())
        ->toContain('no es un decimal válido')
        ->toContain('parser');

    expect($malformed->getMessage())->not->toContain('mayor que cero');
});

it('no se confunde con las dos excepciones de la tasa', function (): void {
    $exception = InvalidRateException::notPositive('0');

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception)->not->toBeInstanceOf(MissingExchangeRateException::class);
    expect($exception)->not->toBeInstanceOf(ExchangeRateUnavailableException::class);
});

it('explica que una tasa manual tiene más decimales de los que se guardan', function (): void {
    expect(InvalidRateException::tooPrecise('42.123456789', 8)->getMessage())
        ->toBe('La tasa "42.123456789" tiene más de 8 decimales, que es lo que se guarda de ella.');
});

it('explica que una tasa manual tiene más cifras enteras de las que caben', function (): void {
    expect(InvalidRateException::tooLarge('12345678901', 10)->getMessage())
        ->toBe('La tasa "12345678901" tiene más de 10 cifras enteras, que es lo que cabe de ella.');
});
