<?php

declare(strict_types=1);

use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\UsageMetric;

it('dice lo que sí se puede seguir haciendo', function (): void {
    expect(CreationSuspendedException::whileCreating(UsageMetric::Users)->getMessage())
        ->toContain('suspendida')
        ->toContain('aprobar')
        ->toContain('Suscripción')
        ->toContain('usuarios');
});

it('se responde con 403 y no con 422', function (): void {
    expect(CreationSuspendedException::STATUS)->toBe(403);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(CreationSuspendedException::whileCreating(UsageMetric::Catalogs))->toBeInstanceOf(RuntimeException::class);
});
