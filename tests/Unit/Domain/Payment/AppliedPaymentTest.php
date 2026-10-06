<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Payment\AllocationPlan;
use App\Domain\Payment\AppliedPayment;
use App\Models\Payment;

/**
 * `AppliedPayment` (`T-058`): `amount = inicial + asignaciones + sobrante`, y
 * el sobrante del reparto es el que guarda el pago. No se construye uno que
 * no cuadre.
 */
function paymentOf(int $amount, int $unallocated): Payment
{
    return (new Payment)->forceFill(['amount_cents_usd' => new Money($amount), 'unallocated_cents' => new Money($unallocated)]);
}

it('cuadra con la inicial, las asignaciones y el sobrante', function (): void {
    $applied = new AppliedPayment(
        paymentOf(11_500, 500),
        new AllocationPlan([['installmentId' => 7, 'cents' => 3_000], ['installmentId' => 8, 'cents' => 3_000]], 500),
        LayawayStatus::PendingDownPayment,
        LayawayStatus::Active,
        downPaymentCents: 5_000,
    );

    expect($applied->activated())->toBeTrue();
});

it('no activa sin inicial', function (): void {
    $applied = new AppliedPayment(paymentOf(3_000, 0), new AllocationPlan([['installmentId' => 7, 'cents' => 3_000]], 0), LayawayStatus::Active, LayawayStatus::Active);

    expect($applied->activated())->toBeFalse();
});

it('rechaza un reparto que no suma el importe', function (int $downPayment): void {
    new AppliedPayment(paymentOf(11_000, 0), new AllocationPlan([['installmentId' => 7, 'cents' => 3_000]], 0), LayawayStatus::PendingDownPayment, LayawayStatus::Active, $downPayment);
})->with([
    'falta un centavo' => [7_999],
    'sobra un centavo' => [8_001],
])->throws(InvalidArgumentException::class, 'invariante dura');

it('rechaza un sobrante distinto del que guarda el pago', function (): void {
    new AppliedPayment(paymentOf(3_000, 0), new AllocationPlan([['installmentId' => 7, 'cents' => 2_000]], 1_000), LayawayStatus::Active, LayawayStatus::Active);
})->throws(InvalidArgumentException::class, 'sin asignar');

it('rechaza una inicial negativa', function (): void {
    new AppliedPayment(paymentOf(3_000, 0), new AllocationPlan([['installmentId' => 7, 'cents' => 3_000]], 0), LayawayStatus::Active, LayawayStatus::Active, -1);
})->throws(InvalidArgumentException::class, 'negativa');
