<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanState;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Subscription\UsageState;
use App\Models\Plan;
use App\Models\Subscription;

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
        ->toBe(['planCode', 'planName', 'status', 'currentPeriodEnd', 'graceEndsAt', 'usage', 'features', 'upgrades', 'featureUpgrades', 'featureLabels', 'metricLabels', 'metricLabelsOne']);

    expect($state['planCode'])->toBe('pro');
    expect($state['status'])->toBe('active');
    expect($state['features'])->toBe(['branding', 'web_push']);
});

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
    expect($state['metricLabelsOne']['catalogs'])->toBe('catálogo publicado');
    expect($state['metricLabelsOne']['storage_mb'])->toBe('MB de almacenamiento');
    expect($state['metricLabels'])->toHaveCount(6);
});

it('lleva las seis métricas y no solo las que se saben medir', function (): void {
    $plan = new Plan(['code' => 'free', 'name' => 'Free', 'max_users' => 1]);
    $subscription = new Subscription(['status' => SubscriptionStatus::Active]);

    $state = planStateFor($plan, $subscription);

    expect($state->usage)->toHaveCount(6);
    expect(array_keys($state->toArray()['usage']))->toBe(array_column(UsageMetric::cases(), 'value'));
    expect($state->usageOf(UsageMetric::Users)->limit)->toBe(1);
});

it('deja la fecha de fin en nulo cuando la suscripción no la tiene', function (): void {
    $plan = new Plan(['code' => 'free', 'name' => 'Free']);
    $subscription = new Subscription(['status' => SubscriptionStatus::Active]);

    $state = planStateFor($plan, $subscription);

    expect($state->currentPeriodEnd)->toBeNull();
    expect($state->graceEndsAt)->toBeNull();
});
