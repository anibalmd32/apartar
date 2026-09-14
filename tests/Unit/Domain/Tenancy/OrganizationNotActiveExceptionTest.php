<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationNotActiveException;

/**
 * `REQ-BOF-001` RB-10.
 *
 * Solo el cierre llega a esta excepción. Una organización **suspendida** no
 * lanza nada: su equipo entra y opera en modo restringido (RB-9), porque un
 * negocio que no ha pagado sigue teniendo clientes que le deben dinero.
 */
it('explica que el negocio cerró su cuenta', function (): void {
    expect(OrganizationNotActiveException::CLOSED_MESSAGE)
        ->toContain('cerró su cuenta')
        ->toContain('backoffice');
});

it('nombra el negocio en el mensaje completo', function (): void {
    expect(OrganizationNotActiveException::closed('Ferretería El Tornillo')->getMessage())
        ->toContain('Ferretería El Tornillo')
        ->toContain(OrganizationNotActiveException::CLOSED_MESSAGE);
});

/**
 * Y no habla de suspensión ni de pagos: quien lee esto no tiene nada que
 * regularizar, su negocio se dio de baja.
 */
it('no confunde el cierre con la suspensión por impago', function (): void {
    expect(OrganizationNotActiveException::CLOSED_MESSAGE)
        ->not->toContain('suspend')
        ->not->toContain('pago');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(OrganizationNotActiveException::closed('Tienda'))->toBeInstanceOf(RuntimeException::class);
});
