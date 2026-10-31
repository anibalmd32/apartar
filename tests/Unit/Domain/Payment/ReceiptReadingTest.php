<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Payment\ReceiptReading;

it('sin lectura no trae nada', function (): void {
    $reading = ReceiptReading::nothing();

    expect($reading->isEmpty())->toBeTrue()
        ->and($reading->toArray())->toBe(['amount' => null, 'currency' => null, 'paidAt' => null, 'reference' => null, 'bank' => null]);
});

it('limpia el monto, la moneda, la fecha y la referencia', function (): void {
    $reading = ReceiptReading::fromAnswer(['amount' => '25,5', 'currency' => 'usd', 'paid_on' => '2026-10-05', 'reference' => 'AB 12 34', 'bank' => ''], '2026-10-01', '2026-10-10');

    expect($reading->amount)->toBe('25.5')
        ->and($reading->currency)->toBe(Currency::USD)
        ->and($reading->paidOn)->toBe('2026-10-05')
        ->and($reading->reference)->toBe('AB1234')
        ->and($reading->bank)->toBeNull()
        ->and($reading->isEmpty())->toBeFalse();
});

it('descarta montos en cero, fechas imposibles o fuera del rango y respuestas que no son texto', function (array $answer): void {
    $reading = ReceiptReading::fromAnswer($answer, '2026-10-01', '2026-10-10');

    expect($reading->amount)->toBeNull()
        ->and($reading->paidOn)->toBeNull();
})->with([
    'cero' => [['amount' => '0.00', 'paid_on' => '2026-02-30']],
    'tres decimales' => [['amount' => '1.234', 'paid_on' => '2026-09-30']],
    'futuro' => [['amount' => 'abc', 'paid_on' => '2026-10-11']],
    'no es texto' => [['amount' => 25, 'paid_on' => ['2026-10-05']]],
]);
