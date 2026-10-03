<?php

declare(strict_types=1);

use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Money\Money;

/**
 * Los cuatro estados de una cuota, con los valores que guarda
 * `installments.status` (`REQ-LAY-004`, [05 §4] bloque 7).
 */
it('declara los cuatro estados de la cuota con sus valores persistidos', function (): void {
    expect(array_map(fn (InstallmentStatus $s): string => $s->value, InstallmentStatus::cases()))
        ->toBe(['pending', 'partially_paid', 'paid', 'overdue']);
});

/**
 * La columna nace en `pending` (`T-039`), y ese valor tiene que ser un caso
 * del enum: si no, la primera cuota materializada no se podría leer.
 */
it('reconoce el valor por defecto de la columna', function (): void {
    expect(InstallmentStatus::from('pending'))->toBe(InstallmentStatus::Pending);
});

/**
 * `REQ-LAY-007` RB-1 y RB-4: el estado se deriva de importe, pagado y gracia,
 * y es la misma regla para la mora (`T-048`) y para los pagos (`T-058`).
 */
it('deriva el estado de importe, pagado y gracia', function (int $paid, bool $pastGrace, InstallmentStatus $expected): void {
    expect(InstallmentStatus::resolve(new Money(3_000), new Money($paid), $pastGrace))->toBe($expected);
})->with([
    'sin pagar, dentro de la gracia' => [0, false, InstallmentStatus::Pending],
    'a medias, dentro de la gracia' => [1_000, false, InstallmentStatus::PartiallyPaid],
    'sin pagar, pasada la gracia' => [0, true, InstallmentStatus::Overdue],
    'a medias, pasada la gracia (SP-2)' => [1_000, true, InstallmentStatus::Overdue],
    'cubierta, pasada la gracia' => [3_000, true, InstallmentStatus::Paid],
    'cubierta de más' => [3_500, false, InstallmentStatus::Paid],
]);

/**
 * `T-053`: para el negocio, una cuota `overdue` está en mora —pasó la gracia—.
 */
it('nombra cada estado de la cuota para el backoffice', function (): void {
    $labels = array_map(fn (InstallmentStatus $status): string => $status->label(), InstallmentStatus::cases());

    expect(array_unique($labels))->toHaveCount(4)
        ->and(InstallmentStatus::Overdue->label())->toBe('En mora');
});
