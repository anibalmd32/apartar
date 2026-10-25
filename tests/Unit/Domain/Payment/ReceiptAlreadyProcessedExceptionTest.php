<?php

declare(strict_types=1);

use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;

it('responde con un 422', function (): void {
    expect(ReceiptAlreadyProcessedException::STATUS)->toBe(422);
});

it('dice quién lo aprobó o lo rechazó', function (): void {
    expect((new ReceiptAlreadyProcessedException(ReceiptStatus::Approved, 'Ana Pérez'))->getMessage())
        ->toBe('Este comprobante ya fue aprobado por Ana Pérez.')
        ->and((new ReceiptAlreadyProcessedException(ReceiptStatus::Rejected, 'Luis'))->getMessage())
        ->toBe('Este comprobante ya fue rechazado por Luis.');
});

it('sin revisor y rechazado al cancelar, lo dice', function (): void {
    expect((new ReceiptAlreadyProcessedException(ReceiptStatus::Rejected, null, RejectionReasonCode::LayawayCancelled))->getMessage())
        ->toContain('al cancelar el apartado');
});

it('sin revisor por otro motivo, no se inventa la cancelación', function (): void {
    expect((new ReceiptAlreadyProcessedException(ReceiptStatus::Approved, null))->getMessage())->toBe('Este comprobante ya fue aprobado.')
        ->and((new ReceiptAlreadyProcessedException(ReceiptStatus::Rejected, null, RejectionReasonCode::NotLegible))->getMessage())->toBe('Este comprobante ya fue rechazado.');
});
