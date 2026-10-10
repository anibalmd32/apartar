<?php

declare(strict_types=1);

use App\Domain\Subscription\InvoiceKind;

/** `T-069` · cada motivo de factura con el nombre con que se lee en el historial. */
it('nombra cada motivo de factura', function (InvoiceKind $kind, string $label): void {
    expect($kind->label())->toBe($label);
})->with([
    [InvoiceKind::PlanChange, 'Cambio de plan'],
    [InvoiceKind::Renewal, 'Renovación'],
    [InvoiceKind::Manual, 'Emitida por la plataforma'],
]);
