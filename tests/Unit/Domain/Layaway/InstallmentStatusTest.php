<?php

declare(strict_types=1);

use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Money\Money;

it('declara los cuatro estados de la cuota con sus valores persistidos', function (): void {
    expect(array_map(fn (InstallmentStatus $s): string => $s->value, InstallmentStatus::cases()))
        ->toBe(['pending', 'partially_paid', 'paid', 'overdue']);
});

it('reconoce el valor por defecto de la columna', function (): void {
    expect(InstallmentStatus::from('pending'))->toBe(InstallmentStatus::Pending);
});

it('deriva el estado de importe, pagado y gracia', function (int $paid, bool $pastGrace, InstallmentStatus $expected): void {
    expect(InstallmentStatus::resolve(new Money(3_000), new Money($paid), $pastGrace))->toBe($expected);
})->with([
    'sin pagar, dentro de la gracia' => [0, false, InstallmentStatus::Pending],
    'a medias, dentro de la gracia' => [1_000, false, InstallmentStatus::PartiallyPaid],
    'sin pagar, pasada la gracia' => [0, true, InstallmentStatus::Overdue],
    'a medias, pasada la gracia' => [1_000, true, InstallmentStatus::Overdue],
    'cubierta, pasada la gracia' => [3_000, true, InstallmentStatus::Paid],
    'cubierta de más' => [3_500, false, InstallmentStatus::Paid],
]);

it('nombra cada estado de la cuota para el backoffice', function (): void {
    $labels = array_map(fn (InstallmentStatus $status): string => $status->label(), InstallmentStatus::cases());

    expect(array_unique($labels))->toHaveCount(4)
        ->and(InstallmentStatus::Overdue->label())->toBe('En mora');
});
