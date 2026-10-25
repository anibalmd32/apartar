<?php

declare(strict_types=1);

use App\Domain\Payment\AllocationPlan;

it('suma lo asignado, lo que sobra y el total, y dice qué recibió cada cuota', function (): void {
    $plan = new AllocationPlan([['installmentId' => 3, 'cents' => 3_000], ['installmentId' => 4, 'cents' => 1_500]], 500);

    expect($plan->allocatedCents())->toBe(4_500)
        ->and($plan->totalCents())->toBe(5_000)
        ->and($plan->isFullyAllocated())->toBeFalse()
        ->and($plan->centsFor(3))->toBe(3_000)
        ->and($plan->centsFor(4))->toBe(1_500)
        ->and($plan->centsFor(5))->toBe(0);
});

it('sin sobrante, está asignado entero; vacío, todo es sobrante', function (): void {
    expect((new AllocationPlan([['installmentId' => 1, 'cents' => 3_000]], 0))->isFullyAllocated())->toBeTrue()
        ->and((new AllocationPlan([], 2_000))->allocatedCents())->toBe(0)
        ->and((new AllocationPlan([], 2_000))->totalCents())->toBe(2_000);
});

it('no se puede construir con una asignación vacía, repetida o un sobrante negativo', function (array $allocations, int $unallocated): void {
    new AllocationPlan($allocations, $unallocated);
})->throws(InvalidArgumentException::class)->with([
    'cero centavos' => [[['installmentId' => 1, 'cents' => 0]], 0],
    'negativa' => [[['installmentId' => 1, 'cents' => -100]], 0],
    'la misma cuota dos veces' => [[['installmentId' => 1, 'cents' => 100], ['installmentId' => 1, 'cents' => 200]], 0],
    'sobrante negativo' => [[['installmentId' => 1, 'cents' => 100]], -1],
]);
