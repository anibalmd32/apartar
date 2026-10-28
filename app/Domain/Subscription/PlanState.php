<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use App\Models\Subscription;

final readonly class PlanState
{
    /**
     * @param  array<string, UsageState>  $usage
     * @param  list<PlanFeature>  $features
     * @param  array<string, Plan|null>  $upgrades
     * @param  array<string, Plan|null>  $featureUpgrades
     */
    private function __construct(
        public string $planCode,
        public string $planName,
        public SubscriptionStatus $status,
        public ?string $currentPeriodEnd,
        public ?string $graceEndsAt,
        public array $usage,
        public array $features,
        public array $upgrades = [],
        public array $featureUpgrades = [],
        public bool $plansOffered = true,
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
        bool $plansOffered = true,
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
            plansOffered: $plansOffered,
        );
    }

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
            'upgrades' => array_map(fn (?Plan $plan): ?array => self::offer($plan), $this->upgrades),
            'featureUpgrades' => array_map(fn (?Plan $plan): ?array => self::offer($plan), $this->featureUpgrades),
            'plansOffered' => $this->plansOffered,
            'featureLabels' => PlanFeature::labels(),
            'metricLabels' => array_combine(
                array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases()),
                array_map(fn (UsageMetric $metric): string => $metric->label(), UsageMetric::cases()),
            ),
            'metricLabelsOne' => array_combine(
                array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases()),
                array_map(fn (UsageMetric $metric): string => $metric->label(1), UsageMetric::cases()),
            ),
        ];
    }
}
