<?php

declare(strict_types=1);

use App\Domain\Subscription\InvoiceKind;

it('nombra cada motivo de factura', function (InvoiceKind $kind, string $label): void {
    expect($kind->label())->toBe($label);
})->with([
    [InvoiceKind::PlanChange, 'Cambio de plan'],
    [InvoiceKind::Renewal, 'Renovación'],
    [InvoiceKind::Manual, 'Emitida por la plataforma'],
]);
