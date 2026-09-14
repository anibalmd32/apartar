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
    ) {}

    /**
     * @param  array<string, UsageState>  $usage
     */
    public static function of(Subscription $subscription, Plan $plan, array $usage): self
    {
        return new self(
            planCode: $plan->code,
            planName: $plan->name,
            status: $subscription->status,
            currentPeriodEnd: $subscription->current_period_end?->toIso8601String(),
            graceEndsAt: $subscription->grace_ends_at?->toIso8601String(),
            usage: $usage,
            features: $plan->enabledFeatures(),
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
        ];
    }
}
