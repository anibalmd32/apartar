<?php

declare(strict_types=1);

use App\Domain\Payment\RejectionReasonCode;

/**
 * Los motivos de rechazo de `REQ-PAY-006`, con el que pone el sistema al
 * cancelar (`REQ-LAY-009` SP-3).
 */
it('declara los siete motivos del contrato del módulo', function (): void {
    expect(array_map(fn (RejectionReasonCode $code): string => $code->value, RejectionReasonCode::cases()))->toBe([
        'not_legible', 'amount_mismatch', 'reference_not_found', 'duplicated', 'wrong_account', 'layaway_cancelled', 'other',
    ]);
});

it('nombra cada motivo sin repetir ninguno', function (): void {
    $labels = array_map(fn (RejectionReasonCode $code): string => $code->label(), RejectionReasonCode::cases());

    expect(array_unique($labels))->toHaveCount(7)
        ->and(RejectionReasonCode::LayawayCancelled->label())->toBe('El apartado fue cancelado');
});

it('ofrece al equipo todos menos el del rechazo automático al cancelar', function (): void {
    expect(RejectionReasonCode::chosenByStaff())->toHaveCount(6)
        ->not->toContain(RejectionReasonCode::LayawayCancelled);
});
