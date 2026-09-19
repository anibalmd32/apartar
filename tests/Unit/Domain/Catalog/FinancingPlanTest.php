<?php

declare(strict_types=1);

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/**
 * El plan de pago que previsualiza el formulario (`REQ-BOF-020` RB-7).
 *
 * Lo que se fija aquí es que **el reparto es el del dominio**: las cuotas
 * salen de `Money::allocateEvenly()` y su suma más la inicial es el precio, al
 * céntimo y para cualquier combinación. Si alguien reescribiera esta cuenta
 * con una división, la invariante sería lo primero en romperse.
 */
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

/**
 * La frase de RB-7: «inicial $50 + 5 cuotas de $30». Cuando el reparto es
 * exacto, todas las cuotas valen lo mismo.
 */
it('reparte en cuotas iguales cuando la división es exacta', function (): void {
    $plan = plan(20_000, 5_000, installments: 5);

    expect($plan->installmentAmount()->cents)->toBe(3_000);
    expect($plan->lastInstallmentAmount()->cents)->toBe(3_000);
    expect($plan->hasUnevenLastInstallment())->toBeFalse();
});

/**
 * **El residuo va a la última**, no se pierde ni se promedia: $100 en tres son
 * `[33.33, 33.33, 33.34]`, porque el céntimo que falta es dinero de alguien.
 */
it('deja el residuo en la última cuota', function (): void {
    $plan = plan(10_000, 0, installments: 3);

    expect($plan->installmentAmount()->cents)->toBe(3_333);
    expect($plan->lastInstallmentAmount()->cents)->toBe(3_334);
    expect($plan->hasUnevenLastInstallment())->toBeTrue();
});

/**
 * La invariante que sostiene todo lo demás, sobre combinaciones incómodas:
 * **inicial + suma de cuotas = precio**, exacto. Es la que se rompería si la
 * pantalla dividiera por su cuenta con coma flotante.
 */
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

/**
 * Con una sola cuota no hay «las demás» con las que diferir, así que la
 * última nunca es desigual: decirlo sería enseñar un aviso sobre un reparto
 * que no ocurrió.
 */
it('no llama desigual a la única cuota', function (): void {
    $plan = plan(7_777, 1_000, installments: 1);

    expect($plan->hasUnevenLastInstallment())->toBeFalse();
    expect($plan->installmentAmount()->cents)->toBe(6_777);
});

/**
 * Las dos guardas del constructor, que protegen la aritmética y no la regla de
 * negocio: las seis de RB-1 las comprueba `CoherentFinancingTerms` antes.
 */
it('rechaza un plan sin cuotas', function (): void {
    plan(10_000, 0, installments: 0);
})->throws(InvalidArgumentException::class, 'al menos una cuota');

it('rechaza una inicial que no deja nada que financiar', function (int $down): void {
    plan(10_000, $down);
})->with([
    'igual al precio' => [10_000],
    'por encima del precio' => [10_001],
])->throws(InvalidArgumentException::class, 'estrictamente menor');

/**
 * Y la variante que usa la previsualización: **devuelve `null` en vez de
 * lanzar**, porque un formulario a medio escribir pasa por esos estados todo
 * el rato y ninguno merece un mensaje.
 */
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

/**
 * El dinero sale en dólares y no en un entero suelto: es lo que impide que
 * alguien sume una cuota con un importe de otra moneda.
 */
it('devuelve las cuotas como importes en dólares', function (): void {
    expect(plan(20_000, 5_000)->installmentAmount()->currency)->toBe(Currency::USD);
});
