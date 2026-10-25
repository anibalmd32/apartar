<?php

declare(strict_types=1);

use App\Domain\Tenancy\InvitationNotPendingException;

it('manda a desactivar en vez de a revocar', function (): void {
    expect(InvitationNotPendingException::for('Ana')->getMessage())
        ->toContain('Ana')
        ->toContain('ya aceptó')
        ->toContain('desactívalo');
});

it('se responde con 422', function (): void {
    expect(InvitationNotPendingException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(InvitationNotPendingException::for('Ana'))->toBeInstanceOf(RuntimeException::class);
});
