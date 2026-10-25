<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentAmounts;

it('devuelve el importe en su moneda cuando es positivo', function (): void {
    $money = PaymentAmounts::assertPositive('850.00', Currency::VES, 'declarado');

    expect($money->cents)->toBe(85_000)
        ->and($money->currency)->toBe(Currency::VES)
        ->and(PaymentAmounts::assertPositive('0.01', Currency::USD, 'conciliado')->cents)->toBe(1);
});

it('rechaza cero, negativos y lo que no es un decimal de dos cifras', function (string $amount): void {
    PaymentAmounts::assertPositive($amount, Currency::USD, 'del pago');
})->throws(InvalidArgumentException::class)->with([
    'cero' => ['0'],
    'cero con decimales' => ['0.00'],
    'negativo' => ['-30.00'],
    'tres decimales' => ['30.001'],
    'separador de millar' => ['1.234,50'],
    'vacío' => [''],
]);

it('acota las cifras enteras a lo que cabe en la columna', function (): void {
    expect(PaymentAmounts::assertPositive('99999999999999.99', Currency::VES, 'declarado')->cents)->toBe(9_999_999_999_999_999)
        ->and(PaymentAmounts::assertPositive('000000000000001.00', Currency::VES, 'declarado')->cents)->toBe(100);

    PaymentAmounts::assertPositive('100000000000000', Currency::VES, 'declarado');
})->throws(InvalidArgumentException::class, 'como mucho 14 cifras');
