<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /** @var class-string<Plan> */
    protected $model = Plan::class;

    /**
     * @return array<model-property<Plan>, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'plan-'.Str::lower(Str::random(8)),
            'name' => 'Plan de prueba',
            'price_cents' => 0,
            'interval' => 'monthly',
            'is_public' => true,
            'position' => 0,
            'max_users' => 5,
            'max_catalogs' => 5,
            'max_products' => 50,
            'max_products_per_catalog' => 25,
            'max_active_layaways' => 25,
            'max_storage_mb' => 1_024,
            'max_payment_accounts' => 5,
            'features' => PlanFeature::noneEnabled() + [
                PlanAllowance::ChatHistoryDays->value => 30,
                PlanAllowance::TelemetryRetentionDays->value => 30,
            ],
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn (): array => [
            'max_users' => null,
            'max_catalogs' => null,
            'max_products' => null,
            'max_products_per_catalog' => null,
            'max_active_layaways' => null,
            'max_storage_mb' => null,
            'max_payment_accounts' => null,
        ]);
    }

    public function withEveryFeature(): static
    {
        return $this->state(fn (): array => [
            'features' => PlanFeature::only(PlanFeature::cases()) + [
                PlanAllowance::ChatHistoryDays->value => null,
                PlanAllowance::TelemetryRetentionDays->value => 365,
            ],
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_public' => false]);
    }
}
