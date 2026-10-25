<?php

declare(strict_types=1);

use App\Actions\Payment\ApproveReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;

it('lleva lo conciliado en su moneda, con o sin tasa manual', function (): void {
    $vigente = new ApproveReceiptData('2550.00', Currency::VES, null, null);
    $manual = new ApproveReceiptData('60.00', Currency::USD, '42.50', 'Pago del lunes, con la tasa de ese día.');

    expect($vigente->settled()->cents)->toBe(255_000)
        ->and($vigente->settled()->currency)->toBe(Currency::VES)
        ->and($vigente->rateOverride)->toBeNull()
        ->and($manual->rateOverride)->toBe('42.50');
});

it('no se construye con un importe de cero o mal escrito', function (string $amount): void {
    new ApproveReceiptData($amount, Currency::USD, null, null);
})->throws(InvalidArgumentException::class)->with(['0', '0.00', '-10.00', '10.001']);

it('no se construye con una tasa manual inservible', function (string $rate): void {
    new ApproveReceiptData('60.00', Currency::VES, $rate, null);
})->throws(InvalidRateException::class)->with(['0', '-1', 'abc', '42.123456789']);

it('no admite una nota de más de 500 caracteres', function (): void {
    new ApproveReceiptData('60.00', Currency::USD, null, str_repeat('a', 501));
})->throws(InvalidArgumentException::class);
