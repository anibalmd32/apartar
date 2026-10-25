<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Models\Plan;

it('nombra el plan que sí la incluye', function (): void {
    $exception = PlanFeatureUnavailableException::of(
        feature: PlanFeature::Branding,
        current: new Plan(['code' => 'free', 'name' => 'Free']),
        minimum: new Plan(['code' => 'pro', 'name' => 'Pro']),
    );

    expect($exception->getMessage())->toContain('Tu plan Free no incluye: tu logo y tu color, sin el sello de Apartar.')->toContain('Pro sí la incluye.');
    expect($exception->getMessage())->not->toContain('branding');
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

it('se responde con 403 y no con 404', function (): void {
    expect(PlanFeatureUnavailableException::STATUS)->toBe(403);
});
