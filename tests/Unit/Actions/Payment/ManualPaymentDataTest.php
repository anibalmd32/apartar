<?php

declare(strict_types=1);

use App\Actions\Payment\ManualPaymentData;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Payment\PaymentMethod;
use Carbon\CarbonImmutable;

function manualPayment(
    string $amount = '30.00',
    ?string $rate = null,
    string $note = 'Pago en efectivo recibido en tienda por Ana.',
    string $key = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f',
): ManualPaymentData {
    return new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, Currency::USD, $rate, CarbonImmutable::parse('2026-10-01'), $note, $key);
}

it('lleva el importe en su moneda y la nota recortada', function (): void {
    $data = manualPayment(note: '  Pago en efectivo recibido en tienda por Ana.  ');

    expect($data->money()->cents)->toBe(3_000)
        ->and($data->note)->toBe('Pago en efectivo recibido en tienda por Ana.')
        ->and($data->idempotencyKey)->toBe('6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f');
});

it('exige una nota de 10 a 500 caracteres', function (string $note): void {
    manualPayment(note: $note);
})->throws(InvalidArgumentException::class)->with(['vacía' => [''], 'corta' => ['efectivo'], 'larga' => [str_repeat('a', 501)]]);

it('no se construye con un importe de cero o negativo', function (string $amount): void {
    manualPayment(amount: $amount);
})->throws(InvalidArgumentException::class)->with(['0', '-30.00']);

it('no se construye con una tasa manual inservible', function (): void {
    manualPayment(rate: '0');
})->throws(InvalidRateException::class);

it('exige que la clave de idempotencia sea un UUID', function (): void {
    manualPayment(key: 'doble-clic');
})->throws(InvalidArgumentException::class);

it('un pago con referencia exige banco y referencia', function (?string $bank, ?string $reference): void {
    new ManualPaymentData(PaymentMethod::PagoMovil, null, '1200.00', Currency::VES, null, CarbonImmutable::parse('2026-10-01'), 'Pago móvil confirmado por Ana.', '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f', bank: $bank, reference: $reference);
})->throws(InvalidArgumentException::class)->with(['sin banco' => [null, '0123456789'], 'sin referencia' => ['Banesco', null], 'referencia larga' => ['Banesco', str_repeat('9', 101)]]);

it('un pago en efectivo no lleva banco ni referencia', function (): void {
    new ManualPaymentData(PaymentMethod::EfectivoUsd, null, '30.00', Currency::USD, null, CarbonImmutable::parse('2026-10-01'), 'Pago en efectivo recibido en tienda por Ana.', '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f', bank: 'Banesco', reference: '0123456789');
})->throws(InvalidArgumentException::class, 'no lleva banco ni referencia');

it('lleva el banco y la referencia al origen del pago', function (): void {
    $data = new ManualPaymentData(PaymentMethod::Transferencia, null, '1200.00', Currency::VES, null, CarbonImmutable::parse('2026-10-01'), 'Transferencia confirmada por Ana.', '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f', bank: 'Banesco', reference: '0123456789');

    expect([$data->origin()->bank, $data->origin()->reference, $data->origin()->attachment])->toBe(['Banesco', '0123456789', null]);
});
