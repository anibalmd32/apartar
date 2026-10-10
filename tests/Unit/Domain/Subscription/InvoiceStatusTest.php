<?php

declare(strict_types=1);

use App\Domain\Subscription\InvoiceStatus;

/**
 * `REQ-SUB-006` RB-7, SP-6, SP-8 · qué estados de factura admiten todavía un
 * comprobante: los pendientes —también tras un rechazo o vencida—, nunca una
 * pagada ni una anulada.
 */
it('admite comprobantes solo mientras la factura es una deuda abierta', function (InvoiceStatus $status, bool $accepts): void {
    expect($status->acceptsReceipts())->toBe($accepts)
        ->and($status->isOutstanding())->toBe($accepts);
})->with([
    [InvoiceStatus::Pending, true],
    [InvoiceStatus::UnderReview, true],
    [InvoiceStatus::Overdue, true],
    [InvoiceStatus::Paid, false],
    [InvoiceStatus::Void, false],
]);

it('declara los cinco estados de InvoiceRow', function (): void {
    expect(array_map(fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::cases()))
        ->toBe(['pending', 'under_review', 'paid', 'overdue', 'void']);
});
