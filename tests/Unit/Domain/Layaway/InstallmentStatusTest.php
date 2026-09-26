<?php

declare(strict_types=1);

use App\Domain\Layaway\InstallmentStatus;

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
