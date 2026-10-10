<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Plan;

/**
 * El `422` que sirve para algo (`REQ-SUB-004` RB-5, `REQ-ORG-004` SP-1).
 *
 * Lo que se comprueba no es que lance —eso lo prueba `LimitEnforcerTest`—
 * sino que **lleva dentro lo que la pantalla necesita**: la métrica, lo
 * consumido, el techo y el plan que lo desbloquea. Sin eso, la interfaz solo
 * puede decir «no puedes», y quien lo lee se queda donde estaba.
 */
function limitTestPlan(string $name, ?int $maxUsers, int $position = 1): Plan
{
    return new Plan([
        'code' => mb_strtolower($name),
        'name' => $name,
        'position' => $position,
        'max_users' => $maxUsers,
    ]);
}

it('nombra el plan que desbloquea el límite', function (): void {
    $exception = PlanLimitReachedException::of(
        metric: UsageMetric::Users,
        used: 1,
        limit: 1,
        amount: 1,
        current: limitTestPlan('Free', 1),
        minimum: limitTestPlan('Pro', 5, position: 2),
    );

    expect($exception->getMessage())
        ->toContain('Tu plan Free permite 1 usuario.')
        ->toContain('Pro permite 5 usuarios.');
});

/**
 * La concordancia no es cosmética: «permite 1 usuarios» delata que el mensaje
 * está hecho con el nombre de una columna, y quien lo lee es el dueño de una
 * ferretería.
 */
it('concuerda el nombre de la métrica con la cifra', function (): void {
    expect(UsageMetric::Users->label(1))->toBe('usuario');
    expect(UsageMetric::Users->label(5))->toBe('usuarios');
});

/**
 * Al que ya tiene el mejor plan no se le ofrece «mejora tu plan»: es la forma
 * más rápida de perder la confianza de quien más paga.
 */
it('no inventa una salida cuando ningún plan la tiene', function (): void {
    $exception = PlanLimitReachedException::of(
        metric: UsageMetric::Users,
        used: 15,
        limit: 15,
        amount: 1,
        current: limitTestPlan('Business', 15, position: 3),
        minimum: null,
    );

    expect($exception->getMessage())->toContain('No hay ningún plan con un techo mayor.');
    expect($exception->minimumPlan)->toBeNull();
    expect($exception->toArray()['minimumPlan'])->toBeNull();
});

it('describe un plan sin techo como ilimitado y no como cero', function (): void {
    $exception = PlanLimitReachedException::of(
        metric: UsageMetric::Users,
        used: 5,
        limit: 5,
        amount: 1,
        current: limitTestPlan('Pro', 5, position: 2),
        minimum: limitTestPlan('Business', null, position: 3),
    );

    expect($exception->getMessage())->toContain('Business permite sin límite.');
});

it('viaja con la métrica, el consumo, el techo y el plan mínimo', function (): void {
    $exception = PlanLimitReachedException::of(
        metric: UsageMetric::Users,
        used: 1,
        limit: 1,
        amount: 1,
        current: limitTestPlan('Free', 1),
        minimum: limitTestPlan('Pro', 5, position: 2),
    );

    expect($exception->toArray())->toBe([
        'metric' => 'users',
        'used' => 1,
        'limit' => 1,
        'minimumPlan' => 'pro',
        'minimumPlanName' => 'Pro',
        'suggestion' => UsageMetric::Users->freeUpHint(),
    ]);

    expect(PlanLimitReachedException::STATUS)->toBe(422);
});
