<?php

declare(strict_types=1);

use App\Domain\Account\PortalAlertKind;

it('ordena las alertas por urgencia', function (): void {
    $kinds = PortalAlertKind::cases();
    usort($kinds, fn (PortalAlertKind $a, PortalAlertKind $b): int => $a->priority() <=> $b->priority());

    expect(array_map(fn (PortalAlertKind $kind): string => $kind->value, $kinds))->toBe([
        'down_payment_window',
        'overdue',
        'receipt_rejected',
        'due_soon',
        'receipt_pending',
        'claim_available',
    ]);
});

it('no repite prioridades', function (): void {
    $priorities = array_map(fn (PortalAlertKind $kind): int => $kind->priority(), PortalAlertKind::cases());

    expect(array_unique($priorities))->toHaveCount(count(PortalAlertKind::cases()));
});
