<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentAmounts;

/**
 * La regla común a los importes de un pago: positivo, de hasta dos decimales,
 * en su moneda (`REQ-PAY-001` RB-5, `REQ-PAY-005` SP-4, `REQ-PAY-007` SP-2).
 */
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
