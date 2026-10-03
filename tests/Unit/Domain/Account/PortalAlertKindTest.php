<?php

declare(strict_types=1);

use App\Domain\Account\PortalAlertKind;

/**
 * `REQ-LAY-023` RB-2: el orden en que se presentan las alertas.
 */
it('ordena las alertas como manda RB-2', function (): void {
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
