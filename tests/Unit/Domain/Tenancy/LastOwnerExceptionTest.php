<?php

declare(strict_types=1);

use App\Domain\Tenancy\LastOwnerException;

/**
 * El mensaje tiene que decir **qué hacer**, porque quien lo recibe suele ser
 * el único dueño intentando irse: "no se puede" sin más lo deja sin salida,
 * cuando la salida existe y es ascender a otra persona antes.
 */
it('nombra a la persona, la operación y la salida', function (): void {
    $exception = LastOwnerException::wouldLeaveNone('Ana', 'degradar');

    expect($exception->getMessage())
        ->toContain('Ana')
        ->toContain('degradar')
        ->toContain('último owner')
        ->toContain('Asciende');
});

/**
 * `REQ-ORG-003` SP-2: es `422`, no `403`. Quien lo intenta tiene permiso de
 * sobra —es el dueño—; lo que no se puede es aceptar el resultado.
 */
it('se responde con 422 y no con 403', function (): void {
    expect(LastOwnerException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(LastOwnerException::wouldLeaveNone('Ana', 'desactivar'))->toBeInstanceOf(RuntimeException::class);
});
