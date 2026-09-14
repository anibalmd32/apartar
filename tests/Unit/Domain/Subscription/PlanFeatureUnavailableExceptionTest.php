<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Models\Plan;

/**
 * `REQ-SUB-004` RB-7 y SP-3 · una función que el plan no incluye.
 *
 * Se comporta como «no disponible» y no como error, y por eso lleva dentro el
 * plan que sí la tiene: RB-7 quiere que el control se muestre deshabilitado
 * **con el motivo**, no oculto. Hay que poder ver lo que uno se está
 * perdiendo.
 */
it('nombra el plan que sí la incluye', function (): void {
    $exception = PlanFeatureUnavailableException::of(
        feature: PlanFeature::Branding,
        current: new Plan(['code' => 'free', 'name' => 'Free']),
        minimum: new Plan(['code' => 'pro', 'name' => 'Pro']),
    );

    expect($exception->getMessage())->toContain('Tu plan Free no incluye branding.')->toContain('Pro sí la incluye.');
    expect($exception->toArray())->toBe([
        'feature' => 'branding',
        'minimumPlan' => 'pro',
        'minimumPlanName' => 'Pro',
    ]);
});

it('no inventa un plan cuando ninguno la tiene', function (): void {
    $exception = PlanFeatureUnavailableException::of(
        feature: PlanFeature::CustomDomain,
        current: new Plan(['code' => 'free', 'name' => 'Free']),
        minimum: null,
    );

    expect($exception->getMessage())->toContain('Ningún plan la incluye todavía.');
    expect($exception->minimumPlan)->toBeNull();
});

/**
 * SP-3 · `403` con el motivo, **nunca `404`**: ocultar que la función existe
 * impide entender que basta con subir de plan.
 */
it('se responde con 403 y no con 404', function (): void {
    expect(PlanFeatureUnavailableException::STATUS)->toBe(403);
});
