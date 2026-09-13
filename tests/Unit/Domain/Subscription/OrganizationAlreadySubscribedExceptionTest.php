<?php

declare(strict_types=1);

use App\Domain\Subscription\OrganizationAlreadySubscribedException;

/**
 * `REQ-SUB-002` RB-1 y SP-2: exactamente una suscripción por organización.
 *
 * Existe para que lo que llegue arriba sea una regla del dominio y no un
 * `QueryException` con el nombre de un índice dentro.
 */
it('nombra la organización y recuerda que solo hay una suscripción', function (): void {
    $exception = OrganizationAlreadySubscribedException::for(42);

    expect($exception->getMessage())
        ->toContain('42')
        ->toContain('exactamente una')
        ->toContain('cambia la que tiene');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(OrganizationAlreadySubscribedException::for(1))->toBeInstanceOf(RuntimeException::class);
});
