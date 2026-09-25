<?php

declare(strict_types=1);

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Layaway\InvalidScheduleException;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;

/**
 * Las condiciones congeladas del apartado (`REQ-LAY-002` RB-2, RB-3;
 * `REQ-LAY-004` RB-7).
 */
function frozenTerms(int $unitPrice = 20_000, int $downPayment = 5_000, int $quantity = 1, int $installments = 5, int $frequency = 15): LayawayTerms
{
    return new LayawayTerms(new Money($unitPrice), new Money($downPayment), $quantity, $installments, $frequency);
}

/**
 * RB-2: el total y la inicial se multiplican por la cantidad; RB-3: las
 * cuotas y la frecuencia, no.
 */
it('multiplica precio e inicial por la cantidad, y no las cuotas ni la frecuencia', function (): void {
    $terms = frozenTerms(quantity: 3);

    expect($terms->total()->cents)->toBe(60_000)
        ->and($terms->totalDownPayment()->cents)->toBe(15_000)
        ->and($terms->financedAmount()->cents)->toBe(45_000)
        ->and($terms->financingPlan()?->installmentsCount)->toBe(5)
        ->and($terms->financingPlan()?->frequencyDays)->toBe(15);
});

/**
 * El plan que devuelve es el del **apartado entero**, no el de una unidad: es
 * el que evita multiplicar un reparto.
 */
it('construye el plan del apartado entero', function (): void {
    $plan = frozenTerms(6_000, 1_000, quantity: 2, installments: 3)->financingPlan();

    expect($plan?->price->cents)->toBe(12_000)
        ->and($plan?->downPayment->cents)->toBe(2_000)
        ->and(array_map(fn (Money $m): int => $m->cents, $plan?->installments() ?? []))->toBe([3_333, 3_333, 3_334]);
});

/**
 * Con una unidad, las entradas son las del producto y el plan también: una
 * sola resta en el sistema, la de `FinancingPlan`.
 */
it('da con una unidad el mismo financiado que el plan del producto', function (): void {
    $product = new FinancingPlan(new Money(19_999), new Money(4_321), 7, 15);

    expect(frozenTerms(19_999, 4_321, installments: 7)->financedAmount()->cents)->toBe($product->financedAmount()->cents);
});

/**
 * SP-2: la inicial es el precio y no queda nada que financiar. No hay plan,
 * y el financiado es un cero en la moneda del apartado.
 */
it('no tiene plan ni financiado cuando la inicial es el precio', function (): void {
    $terms = frozenTerms(15_000, 15_000, quantity: 2);

    expect($terms->financingPlan())->toBeNull()
        ->and($terms->financedAmount()->cents)->toBe(0)
        ->and($terms->financedAmount()->currency)->toBe(Currency::USD);
});

/**
 * RB-7, con los extremos dentro.
 */
it('admite los extremos de los rangos', function (int $installments, int $frequency): void {
    expect(frozenTerms(installments: $installments, frequency: $frequency)->installmentsCount)->toBe($installments);
})->with([[1, 1], [36, 90], [1, 90], [36, 1]]);

/**
 * RB-7: fuera de rango no es un cronograma raro, es `InvalidScheduleException`
 * con el rango en el mensaje. Y salta **al congelar las condiciones**, que es
 * al crear el apartado, no al activarlo con la inicial ya pagada.
 */
it('rechaza las cuotas fuera de rango diciendo el rango', function (int $installments): void {
    expect(fn () => frozenTerms(installments: $installments))->toThrow(
        InvalidScheduleException::class,
        "Un apartado se paga en entre 1 y 36 cuotas, y se pidieron {$installments}.",
    );
})->with([0, 37, -1]);

it('rechaza la frecuencia fuera de rango diciendo el rango', function (int $frequency): void {
    expect(fn () => frozenTerms(frequency: $frequency))->toThrow(
        InvalidScheduleException::class,
        "Las cuotas van cada 1 a 90 días, y se pidieron {$frequency}.",
    );
})->with([0, 91, 365]);

it('rechaza una cantidad menor que uno', function (): void {
    expect(fn () => frozenTerms(quantity: 0))->toThrow(InvalidArgumentException::class, 'al menos una unidad');
});

it('rechaza una inicial negativa o por encima del precio', function (int $downPayment): void {
    expect(fn () => frozenTerms(10_000, $downPayment))->toThrow(InvalidArgumentException::class, 'La cuota inicial va de cero al precio');
})->with([-1, 10_001]);

it('rechaza mezclar monedas', function (): void {
    expect(fn () => new LayawayTerms(new Money(10_000), new Money(1_000, Currency::VES), 1, 3, 15))
        ->toThrow(CurrencyMismatchException::class);
});
