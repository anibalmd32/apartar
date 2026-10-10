<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanState;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Subscription\UsageState;
use App\Models\Plan;
use App\Models\Subscription;

/**
 * Lo que el plan le cuenta a la interfaz (`REQ-SUB-003` RB-6).
 *
 * Se construye sin base de datos: `PlanState` no consulta nada, solo compone
 * lo que ya se leyó. Lo que importa es que su forma sea **exactamente** la del
 * espejo de `resources/js/types/plan.ts`, porque una clave que no coincide no
 * la detecta nadie hasta que una pantalla muestra un hueco.
 */
function planStateFor(Plan $plan, Subscription $subscription): PlanState
{
    $usage = [];

    foreach (UsageMetric::cases() as $metric) {
        $usage[$metric->value] = UsageState::of($metric, 1, $plan->limitFor($metric));
    }

    return PlanState::of($subscription, $plan, $usage);
}

it('viaja con la forma exacta del contrato tipado', function (): void {
    $plan = new Plan([
        'code' => 'pro',
        'name' => 'Pro',
        'max_users' => 5,
        'features' => PlanFeature::only([PlanFeature::Branding, PlanFeature::WebPush]),
    ]);

    $subscription = new Subscription(['status' => SubscriptionStatus::Active]);

    $state = planStateFor($plan, $subscription)->toArray();

    expect(array_keys($state))
        ->toBe(['planCode', 'planName', 'status', 'currentPeriodEnd', 'graceEndsAt', 'usage', 'features', 'upgrades', 'featureUpgrades', 'featureLabels', 'metricLabels']);

    expect($state['planCode'])->toBe('pro');
    expect($state['status'])->toBe('active');
    expect($state['features'])->toBe(['branding', 'web_push']);
});

/**
 * `T-067` · lo que `LimitBanner` y `UpgradePrompt` dicen sin decidir: el plan
 * que desbloquea, ya resuelto, y los nombres de funciones y métricas, que son
 * del servidor (`PlanFeature::label()`, `UsageMetric::label()`).
 */
it('lleva resueltos los planes que desbloquean y los nombres', function (): void {
    $plan = new Plan(['code' => 'free', 'name' => 'Free', 'features' => PlanFeature::noneEnabled()]);
    $pro = new Plan(['code' => 'pro', 'name' => 'Pro']);
    $usage = [];

    foreach (UsageMetric::cases() as $metric) {
        $usage[$metric->value] = UsageState::of($metric, 1, 10);
    }

    $state = PlanState::of(new Subscription(['status' => SubscriptionStatus::Active]), $plan, $usage, ['products' => $pro], ['excel_export' => $pro, 'custom_domain' => null])->toArray();

    expect($state['upgrades'])->toBe(['products' => ['planCode' => 'pro', 'planName' => 'Pro']]);
    expect($state['featureUpgrades'])->toBe(['excel_export' => ['planCode' => 'pro', 'planName' => 'Pro'], 'custom_domain' => null]);
    expect($state['featureLabels'])->toBe(PlanFeature::labels());
    expect($state['metricLabels']['catalogs'])->toBe('catálogos publicados');
    expect($state['metricLabels'])->toHaveCount(6);
});

/**
 * Las seis van siempre, incluidas las cuatro cuyas tablas no existen todavía.
 * Un hueco obligaría a cada lectura de la interfaz a contemplar la ausencia,
 * que es la segunda fuente de verdad que este objeto existe para evitar.
 */
it('lleva las seis métricas y no solo las que se saben medir', function (): void {
    $plan = new Plan(['code' => 'free', 'name' => 'Free', 'max_users' => 1]);
    $subscription = new Subscription(['status' => SubscriptionStatus::Active]);

    $state = planStateFor($plan, $subscription);

    expect($state->usage)->toHaveCount(6);
    expect(array_keys($state->toArray()['usage']))->toBe(array_column(UsageMetric::cases(), 'value'));
    expect($state->usageOf(UsageMetric::Users)->limit)->toBe(1);
});

/**
 * `REQ-SUB-002` RB-2 · en Free la suscripción **no tiene fecha de fin**, y eso
 * llega como `null` y no como una fecha inventada: la capa gratuita es la
 * prueba, y no hay reloj que se agote.
 */
it('deja la fecha de fin en nulo cuando la suscripción no la tiene', function (): void {
    $plan = new Plan(['code' => 'free', 'name' => 'Free']);
    $subscription = new Subscription(['status' => SubscriptionStatus::Active]);

    $state = planStateFor($plan, $subscription);

    expect($state->currentPeriodEnd)->toBeNull();
    expect($state->graceEndsAt)->toBeNull();
});
