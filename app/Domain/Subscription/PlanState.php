<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use App\Models\Subscription;

/**
 * Lo que el backoffice necesita saber del plan en **todas** sus pantallas
 * (`REQ-SUB-003` RB-6).
 *
 * Viaja en las page props para que la interfaz pueda avisar **antes** de que
 * alguien choque con un límite, y para que lo que pinta salga de un solo
 * cálculo hecho en el servidor. `REQ-SUB-004` RB-1 lo dice sin rodeos: el
 * frontend nunca decide, solo refleja.
 *
 * Espejo exacto de `PlanState` en `resources/js/types/plan.ts`.
 */
final readonly class PlanState
{
    /**
     * @param  array<string, UsageState>  $usage  una entrada por caso de `UsageMetric`
     * @param  list<PlanFeature>  $features  las que el plan incluye
     * @param  array<string, Plan|null>  $upgrades  por métrica a partir del 80 %: el plan que da más sitio (`REQ-SUB-003` RB-7)
     * @param  array<string, Plan|null>  $featureUpgrades  por función que falta: el plan más barato que la incluye
     */
    private function __construct(
        public string $planCode,
        public string $planName,
        public SubscriptionStatus $status,
        /** `null` en Free: la suscripción no tiene fecha de fin (`REQ-SUB-002` RB-2). */
        public ?string $currentPeriodEnd,
        public ?string $graceEndsAt,
        public array $usage,
        public array $features,
        public array $upgrades = [],
        public array $featureUpgrades = [],
    ) {}

    /**
     * @param  array<string, UsageState>  $usage
     * @param  array<string, Plan|null>  $upgrades
     * @param  array<string, Plan|null>  $featureUpgrades
     */
    public static function of(
        Subscription $subscription,
        Plan $plan,
        array $usage,
        array $upgrades = [],
        array $featureUpgrades = [],
    ): self {
        return new self(
            planCode: $plan->code,
            planName: $plan->name,
            status: $subscription->status,
            currentPeriodEnd: $subscription->current_period_end?->toIso8601String(),
            graceEndsAt: $subscription->grace_ends_at?->toIso8601String(),
            usage: $usage,
            features: $plan->enabledFeatures(),
            upgrades: $upgrades,
            featureUpgrades: $featureUpgrades,
        );
    }

    /**
     * Consumo de una métrica concreta.
     */
    public function usageOf(UsageMetric $metric): UsageState
    {
        return $this->usage[$metric->value];
    }

    /**
     * @return array{planCode: string, planName: string}|null
     */
    private static function offer(?Plan $plan): ?array
    {
        return $plan instanceof Plan ? ['planCode' => $plan->code, 'planName' => $plan->name] : null;
    }

    /**
     * Forma con la que viaja a las page props.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'planCode' => $this->planCode,
            'planName' => $this->planName,
            'status' => $this->status->value,
            'currentPeriodEnd' => $this->currentPeriodEnd,
            'graceEndsAt' => $this->graceEndsAt,
            'usage' => array_map(
                fn (UsageState $state): array => $state->toArray(),
                $this->usage,
            ),
            'features' => array_map(
                fn (PlanFeature $feature): string => $feature->value,
                $this->features,
            ),
            // `T-067` · lo que `LimitBanner` y `UpgradePrompt` dicen sin
            // decidir nada (RB-1): el plan que desbloquea, ya resuelto aquí, y
            // el nombre de cada función, que es de `PlanFeature::label()`.
            'upgrades' => array_map(fn (?Plan $plan): ?array => self::offer($plan), $this->upgrades),
            'featureUpgrades' => array_map(fn (?Plan $plan): ?array => self::offer($plan), $this->featureUpgrades),
            'featureLabels' => PlanFeature::labels(),
            'metricLabels' => array_combine(
                array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases()),
                array_map(fn (UsageMetric $metric): string => $metric->label(), UsageMetric::cases()),
            ),
        ];
    }
}
