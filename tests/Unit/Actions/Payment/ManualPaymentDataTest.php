<?php

declare(strict_types=1);

use App\Actions\Payment\ManualPaymentData;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Payment\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * Un pago registrado a mano por el negocio (`REQ-PAY-007`).
 */
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

/** RB-1, SP-1 · la nota es la única evidencia: obligatoria, de 10 a 500. */
it('exige una nota de 10 a 500 caracteres', function (string $note): void {
    manualPayment(note: $note);
})->throws(InvalidArgumentException::class)->with(['vacía' => [''], 'corta' => ['efectivo'], 'larga' => [str_repeat('a', 501)]]);

/** SP-2 · ni cero ni negativo. */
it('no se construye con un importe de cero o negativo', function (string $amount): void {
    manualPayment(amount: $amount);
})->throws(InvalidArgumentException::class)->with(['0', '-30.00']);

it('no se construye con una tasa manual inservible', function (): void {
    manualPayment(rate: '0');
})->throws(InvalidRateException::class);

/** SP-5 · la clave del doble clic es el UUID del formulario. */
it('exige que la clave de idempotencia sea un UUID', function (): void {
    manualPayment(key: 'doble-clic');
})->throws(InvalidArgumentException::class);
