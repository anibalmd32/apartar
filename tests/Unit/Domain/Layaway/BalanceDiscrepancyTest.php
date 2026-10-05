<?php

declare(strict_types=1);

use App\Domain\Layaway\BalanceDiscrepancy;

/**
 * `BalanceDiscrepancy` (`T-057`): lo que `diff()` encontró, sin poder
 * construirse vacío ni con una columna «descuadrada» que no lo está.
 */
it('no existe sin nada descuadrado', function (): void {
    new BalanceDiscrepancy(layawayId: 1, fields: [], paidFromPayments: 5_000, paidFromSchedule: 5_000, overpaidCents: 0);
})->throws(InvalidArgumentException::class, 'diff() devuelve null');

it('rechaza una columna cuyo valor guardado es el derivado', function (): void {
    new BalanceDiscrepancy(
        layawayId: 1,
        fields: ['paid_cents' => ['stored' => 5_000, 'derived' => 5_000]],
        paidFromPayments: 5_000,
        paidFromSchedule: 5_000,
        overpaidCents: 0,
    );
})->throws(InvalidArgumentException::class, 'paid_cents');

it('rechaza un exceso negativo', function (): void {
    new BalanceDiscrepancy(layawayId: 1, fields: [], paidFromPayments: 1, paidFromSchedule: 2, overpaidCents: -1);
})->throws(InvalidArgumentException::class, 'negativo');

it('se arregla recalculando cuando solo hay columnas desactualizadas', function (): void {
    $discrepancy = new BalanceDiscrepancy(
        layawayId: 1,
        fields: ['next_due_date' => ['stored' => null, 'derived' => '2026-09-25']],
        paidFromPayments: 5_000,
        paidFromSchedule: 5_000,
        overpaidCents: 0,
    );

    expect($discrepancy->ledgerMismatch())->toBeFalse();
    expect($discrepancy->isFixedByRecalculating())->toBeTrue();
});

it('no se arregla recalculando si los pagos y las cuotas no cuentan lo mismo', function (): void {
    $discrepancy = new BalanceDiscrepancy(layawayId: 1, fields: [], paidFromPayments: 8_000, paidFromSchedule: 5_000, overpaidCents: 0);

    expect($discrepancy->ledgerMismatch())->toBeTrue();
    expect($discrepancy->isFixedByRecalculating())->toBeFalse();
});

it('no se arregla recalculando si lo pagado excede el total', function (): void {
    $discrepancy = new BalanceDiscrepancy(layawayId: 1, fields: [], paidFromPayments: 20_100, paidFromSchedule: 20_100, overpaidCents: 100);

    expect($discrepancy->ledgerMismatch())->toBeFalse();
    expect($discrepancy->isFixedByRecalculating())->toBeFalse();
});
