<?php

declare(strict_types=1);

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;

function plan(int $priceCents, int $downCents, int $installments = 4, int $frequency = 15): FinancingPlan
{
    return new FinancingPlan(
        new Money($priceCents),
        new Money($downCents),
        $installments,
        $frequency,
    );
}

it('financia lo que queda después de la inicial', function (): void {
    expect(plan(25_000, 5_000)->financedAmount()->cents)->toBe(20_000);
});

it('reparte en cuotas iguales cuando la división es exacta', function (): void {
    $plan = plan(20_000, 5_000, installments: 5);

    expect($plan->installmentAmount()->cents)->toBe(3_000);
    expect($plan->lastInstallmentAmount()->cents)->toBe(3_000);
    expect($plan->hasUnevenLastInstallment())->toBeFalse();
});

it('deja el residuo en la última cuota', function (): void {
    $plan = plan(10_000, 0, installments: 3);

    expect($plan->installmentAmount()->cents)->toBe(3_333);
    expect($plan->lastInstallmentAmount()->cents)->toBe(3_334);
    expect($plan->hasUnevenLastInstallment())->toBeTrue();
});

it('suma exactamente el precio en cualquier reparto', function (int $price, int $down, int $count): void {
    $plan = plan($price, $down, installments: $count);

    $total = array_reduce(
        $plan->installments(),
        fn (int $carry, Money $installment): int => $carry + $installment->cents,
        $plan->downPayment->cents,
    );

    expect($total)->toBe($price);
    expect($plan->installments())->toHaveCount($count);
})->with([
    'tres cuotas de un importe con residuo' => [10_000, 0, 3],
    'siete cuotas' => [99_999, 1, 7],
    'una sola cuota' => [12_345, 45, 1],
    'el máximo de cuotas' => [1_000_000, 999, 36],
    'un céntimo por encima de la inicial' => [5_001, 5_000, 3],
]);

it('no llama desigual a la única cuota', function (): void {
    $plan = plan(7_777, 1_000, installments: 1);

    expect($plan->hasUnevenLastInstallment())->toBeFalse();
    expect($plan->installmentAmount()->cents)->toBe(6_777);
});

it('rechaza un plan sin cuotas', function (): void {
    plan(10_000, 0, installments: 0);
})->throws(InvalidArgumentException::class, 'al menos una cuota');

it('rechaza una inicial que no deja nada que financiar', function (int $down): void {
    plan(10_000, $down);
})->with([
    'igual al precio' => [10_000],
    'por encima del precio' => [10_001],
])->throws(InvalidArgumentException::class, 'estrictamente menor');

it('devuelve null en lugar de lanzar cuando el plan es imposible', function (int $price, int $down, int $count): void {
    expect(FinancingPlan::tryFrom(new Money($price), new Money($down), $count, 15))->toBeNull();
})->with([
    'sin cuotas' => [10_000, 0, 0],
    'inicial igual al precio' => [10_000, 10_000, 4],
    'formulario vacío' => [0, 0, 4],
]);

it('devuelve el plan cuando los términos cuadran', function (): void {
    $plan = FinancingPlan::tryFrom(new Money(20_000), new Money(5_000), 5, 15);

    expect($plan)->toBeInstanceOf(FinancingPlan::class);
    expect($plan?->installmentAmount()->cents)->toBe(3_000);
});

it('devuelve las cuotas como importes en dólares', function (): void {
    expect(plan(20_000, 5_000)->installmentAmount()->currency)->toBe(Currency::USD);
});
