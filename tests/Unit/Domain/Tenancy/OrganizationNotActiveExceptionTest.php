<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationNotActiveException;

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

it('no confunde el cierre con la suspensión por impago', function (): void {
    expect(OrganizationNotActiveException::CLOSED_MESSAGE)
        ->not->toContain('suspend')
        ->not->toContain('pago');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(OrganizationNotActiveException::closed('Tienda'))->toBeInstanceOf(RuntimeException::class);
});
