<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    private const MB_PER_GB = 1_024;

    public function run(): void
    {
        foreach ($this->matrix() as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matrix(): array
    {
        return [
            [
                'code' => 'free',
                'name' => 'Gratis',
                'price_cents' => 0,
                'interval' => 'monthly',
                'is_public' => true,
                'position' => 1,

                'max_users' => 1,
                'max_catalogs' => 1,
                'max_products' => 30,
                'max_products_per_catalog' => 20,
                'max_active_layaways' => 15,
                'max_storage_mb' => 200,
                'max_payment_accounts' => 1,

                'features' => PlanFeature::only([]) + [
                    PlanAllowance::ChatHistoryDays->value => 30,
                    PlanAllowance::TelemetryRetentionDays->value => 30,
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'price_cents' => 1_500,
                'interval' => 'monthly',
                'is_public' => false,
                'position' => 2,

                'max_users' => 5,
                'max_catalogs' => 10,
                'max_products' => 300,
                'max_products_per_catalog' => 100,
                'max_active_layaways' => 200,
                'max_storage_mb' => 5 * self::MB_PER_GB,
                'max_payment_accounts' => 5,

                'features' => PlanFeature::only([
                    PlanFeature::Branding,
                    PlanFeature::ExcelExport,
                    PlanFeature::WebPush,
                    PlanFeature::SubscriberDigest,
                    PlanFeature::FullAnalytics,
                ]) + [
                    PlanAllowance::ChatHistoryDays->value => null,
                    PlanAllowance::TelemetryRetentionDays->value => 180,
                ],
            ],
            [
                'code' => 'business',
                'name' => 'Negocio',
                'price_cents' => 3_900,
                'interval' => 'monthly',
                'is_public' => false,
                'position' => 3,

                'max_users' => 15,
                'max_catalogs' => 50,
                'max_products' => 2_000,
                'max_products_per_catalog' => 500,
                'max_active_layaways' => 2_000,
                'max_storage_mb' => 25 * self::MB_PER_GB,
                'max_payment_accounts' => 15,

                'features' => PlanFeature::only([
                    PlanFeature::Branding,
                    PlanFeature::ExcelExport,
                    PlanFeature::WebPush,
                    PlanFeature::SubscriberDigest,
                    PlanFeature::FullAnalytics,
                    PlanFeature::CustomDomain,
                ]) + [
                    PlanAllowance::ChatHistoryDays->value => null,
                    PlanAllowance::TelemetryRetentionDays->value => 365,
                ],
            ],
        ];
    }
}
