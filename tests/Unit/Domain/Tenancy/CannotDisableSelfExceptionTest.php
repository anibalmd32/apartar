<?php

declare(strict_types=1);

use App\Domain\Tenancy\CannotDisableSelfException;

/**
 * `REQ-ORG-005` RB-4 y SP-1 · nadie se desactiva a sí mismo.
 *
 * El mensaje tiene que decir por qué y adónde ir: quien se desactiva pierde la
 * sesión en su siguiente petición y ya no puede volver a activarse, así que el
 * remedio no está en sus manos.
 */
it('explica el motivo y a quién pedírselo', function (): void {
    expect(CannotDisableSelfException::make()->getMessage())
        ->toContain('tu propia cuenta')
        ->toContain('otro owner');
});

/**
 * Es `422` y no `403`: quien lo intenta es el `owner`, que puede desactivar a
 * cualquiera de su equipo. Lo que no se puede es aceptar este caso.
 */
it('se responde con 422 y no con 403', function (): void {
    expect(CannotDisableSelfException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(CannotDisableSelfException::make())->toBeInstanceOf(RuntimeException::class);
});
