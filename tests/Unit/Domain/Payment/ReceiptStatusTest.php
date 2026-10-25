<?php

declare(strict_types=1);

use App\Domain\Payment\ReceiptStatus;

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

it('nombra cada estado en la bandeja del negocio', function (): void {
    expect(array_map(fn (ReceiptStatus $status): string => $status->label(), ReceiptStatus::cases()))
        ->toBe(['En revisión', 'Aprobado', 'Rechazado']);
});
