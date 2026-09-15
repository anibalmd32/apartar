<?php

declare(strict_types=1);

use App\Domain\Tenancy\SlugLockedException;

/**
 * `REQ-ORG-006` SP-5 · el enlace del negocio ya no se puede cambiar.
 *
 * El mensaje tiene que explicar **el daño**, no la restricción: la vitrina se
 * comparte por WhatsApp y se pega en una bio de Instagram, así que cambiar el
 * enlace no da un error, deja de traer clientes. Quien lo lee tiene que
 * entender por qué la plataforma le está diciendo que no.
 */
it('explica que rompería los enlaces ya compartidos', function (): void {
    expect(SlugLockedException::hasPublishedCatalogs('ferreteria-el-tornillo')->getMessage())
        ->toContain('ferreteria-el-tornillo')
        ->toContain('catálogos publicados')
        ->toContain('ya compartiste');
});

/**
 * `422` y no `403`: la petición está bien formada y quien la hace tiene
 * permiso de sobra. Lo que no se puede es aceptarla.
 */
it('se responde con 422 y no con 403', function (): void {
    expect(SlugLockedException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(SlugLockedException::hasPublishedCatalogs('tienda'))->toBeInstanceOf(RuntimeException::class);
});
