<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Plan;

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

it('concuerda el nombre de la métrica con la cifra', function (): void {
    expect(UsageMetric::Users->label(1))->toBe('usuario');
    expect(UsageMetric::Users->label(5))->toBe('usuarios');
});

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
