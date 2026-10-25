<?php

declare(strict_types=1);

use App\Domain\Subscription\InvoiceStatus;

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
