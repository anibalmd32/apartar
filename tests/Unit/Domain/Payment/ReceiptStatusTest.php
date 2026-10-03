<?php

declare(strict_types=1);

use App\Domain\Payment\ReceiptStatus;

/**
 * Los tres estados de un comprobante (`REQ-PAY-001` RB-6) y cuáles retienen
 * la expiración de un apartado (`REQ-LAY-006` RB-1, RB-2).
 */
it('declara los tres estados con sus valores persistidos', function (): void {
    expect(array_map(fn (ReceiptStatus $status): string => $status->value, ReceiptStatus::cases()))
        ->toBe(['pending_review', 'approved', 'rejected']);
});

it('retiene la expiración en revisión y aprobado, no rechazado', function (): void {
    expect(ReceiptStatus::holdingExpiration())->toBe([ReceiptStatus::PendingReview, ReceiptStatus::Approved])
        ->and(ReceiptStatus::Rejected->holdsExpiration())->toBeFalse();
});

it('solo espera al negocio mientras está en revisión', function (): void {
    expect(array_map(fn (ReceiptStatus $status): bool => $status->awaitsReview(), ReceiptStatus::cases()))
        ->toBe([true, false, false]);
});
