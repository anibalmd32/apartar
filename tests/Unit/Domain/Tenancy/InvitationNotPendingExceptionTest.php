<?php

declare(strict_types=1);

use App\Domain\Tenancy\InvitationNotPendingException;

/**
 * `REQ-ORG-004` RB-4 y RB-6 · reenviar o revocar la invitación de alguien que
 * ya la aceptó.
 *
 * El mensaje redirige a la operación correcta, que es la baja lógica: revocar
 * a un compañero sería borrarlo, y `REQ-ORG-005` RB-1 lo prohíbe para no
 * perder su rastro en la auditoría.
 */
it('manda a desactivar en vez de a revocar', function (): void {
    expect(InvitationNotPendingException::for('Ana')->getMessage())
        ->toContain('Ana')
        ->toContain('ya aceptó')
        ->toContain('desactívalo');
});

/**
 * `422`: la petición está bien formada, pero llega tarde. Normalmente porque
 * dos pestañas de Equipo muestran estados distintos.
 */
it('se responde con 422', function (): void {
    expect(InvitationNotPendingException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(InvitationNotPendingException::for('Ana'))->toBeInstanceOf(RuntimeException::class);
});
