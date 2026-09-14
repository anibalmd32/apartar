<?php

declare(strict_types=1);

use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\UsageMetric;

/**
 * `REQ-SUB-004` RB-8 · con la suscripción suspendida no se crea nada.
 *
 * Tiene excepción propia y no reutiliza la del límite por un motivo concreto:
 * decirle a quien está suspendido que «alcanzó el límite de su plan» lo manda
 * a comparar planes en vez de a pagar lo que debe.
 */
it('dice lo que sí se puede seguir haciendo', function (): void {
    expect(CreationSuspendedException::whileCreating(UsageMetric::Users)->getMessage())
        ->toContain('suspendida')
        ->toContain('aprobar')
        ->toContain('Suscripción')
        ->toContain('usuarios');
});

/**
 * Es `403` y no `422` (`REQ-ORG-008` SP-3): no falta sitio, falta estar al
 * día. Es el mismo código con el que responde `EnsureOrganizationIsActive` en
 * las rutas que no declaran `during-suspension`.
 */
it('se responde con 403 y no con 422', function (): void {
    expect(CreationSuspendedException::STATUS)->toBe(403);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(CreationSuspendedException::whileCreating(UsageMetric::Catalogs))->toBeInstanceOf(RuntimeException::class);
});
