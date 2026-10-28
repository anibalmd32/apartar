<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PlanSeederTest extends TestCase
{
    use ReusesMigratedDatabase;

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function matrix(): array
    {
        return [
            'free' => ['free', [
                'name' => 'Gratis',
                'position' => 1,
                'max_users' => 1,
                'max_catalogs' => 1,
                'max_products' => 30,
                'max_products_per_catalog' => 20,
                'max_active_layaways' => 15,
                'max_storage_mb' => 200,
                'max_payment_accounts' => 1,
            ]],
            'pro' => ['pro', [
                'name' => 'Pro',
                'position' => 2,
                'max_users' => 5,
                'max_catalogs' => 10,
                'max_products' => 300,
                'max_products_per_catalog' => 100,
                'max_active_layaways' => 200,
                'max_storage_mb' => 5120,
                'max_payment_accounts' => 5,
            ]],
            'business' => ['business', [
                'name' => 'Negocio',
                'position' => 3,
                'max_users' => 15,
                'max_catalogs' => 50,
                'max_products' => 2000,
                'max_products_per_catalog' => 500,
                'max_active_layaways' => 2000,
                'max_storage_mb' => 25600,
                'max_payment_accounts' => 15,
            ]],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: list<PlanFeature>}>
     */
    public static function features(): array
    {
        $paid = [
            PlanFeature::Branding,
            PlanFeature::ExcelExport,
            PlanFeature::WebPush,
            PlanFeature::SubscriberDigest,
            PlanFeature::FullAnalytics,
        ];

        return [
            'free' => ['free', []],
            'pro' => ['pro', $paid],
            'business' => ['business', [...$paid, PlanFeature::CustomDomain]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_it_seeds_the_three_plans(): void
    {
        $this->assertSame(3, Plan::query()->count());
        $this->assertSame(
            ['free', 'pro', 'business'],
            Plan::query()->orderBy('position')->pluck('code')->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('matrix')]
    public function test_each_plan_matches_the_approved_matrix(string $code, array $expected): void
    {
        $plan = $this->plan($code);

        foreach ($expected as $column => $value) {
            $this->assertSame($value, $plan->getAttribute($column), sprintf(
                'El plan %s tiene %s = %s y la matriz dice %s.',
                $code,
                $column,
                var_export($plan->getAttribute($column), true),
                var_export($value, true),
            ));
        }
    }

    /**
     * @param  list<PlanFeature>  $enabled
     */
    #[DataProvider('features')]
    public function test_each_plan_has_exactly_its_features(string $code, array $enabled): void
    {
        $plan = $this->plan($code);

        foreach (PlanFeature::cases() as $feature) {
            $this->assertSame(
                in_array($feature, $enabled, true),
                $plan->hasFeature($feature),
                sprintf('El plan %s y la función %s no coinciden con la matriz.', $code, $feature->value),
            );
        }
    }

    public function test_the_platform_badge_follows_branding(): void
    {
        $this->assertTrue($this->plan('free')->showsPlatformBadge());
        $this->assertFalse($this->plan('pro')->showsPlatformBadge());
        $this->assertFalse($this->plan('business')->showsPlatformBadge());
    }

    public function test_it_seeds_the_numeric_allowances(): void
    {
        $this->assertSame(30, $this->plan('free')->allowance(PlanAllowance::ChatHistoryDays));
        $this->assertNull($this->plan('pro')->allowance(PlanAllowance::ChatHistoryDays));
        $this->assertNull($this->plan('business')->allowance(PlanAllowance::ChatHistoryDays));

        $this->assertSame(30, $this->plan('free')->allowance(PlanAllowance::TelemetryRetentionDays));
        $this->assertSame(180, $this->plan('pro')->allowance(PlanAllowance::TelemetryRetentionDays));
        $this->assertSame(365, $this->plan('business')->allowance(PlanAllowance::TelemetryRetentionDays));
    }

    public function test_no_plan_limits_catalog_subscribers(): void
    {
        foreach (Plan::query()->get() as $plan) {
            $this->assertArrayNotHasKey('max_subscribers', $plan->getAttributes());
            $this->assertArrayNotHasKey('subscribers', (array) $plan->features);
        }
    }

    public function test_only_the_free_plan_is_public_while_the_paid_ones_stay_hidden(): void
    {
        $this->assertSame(['free'], Plan::query()->public()->pluck('code')->all());
        $this->assertSame(3, Plan::query()->count());
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $before = Plan::query()->orderBy('position')->get()->map->only([
            'code', 'name', 'max_users', 'max_products', 'max_storage_mb', 'features',
        ]);

        $created = $this->plan('free')->created_at;

        $this->seed(PlanSeeder::class);

        $this->assertSame(3, Plan::query()->count());

        $after = Plan::query()->orderBy('position')->get()->map->only([
            'code', 'name', 'max_users', 'max_products', 'max_storage_mb', 'features',
        ]);

        $this->assertEquals($before->all(), $after->all());

        $this->assertEquals($created, $this->plan('free')->created_at);
    }

    public function test_seeding_restores_a_hand_edited_limit(): void
    {
        $this->plan('free')->update(['max_products' => 9_000]);

        $this->seed(PlanSeeder::class);

        $this->assertSame(30, $this->plan('free')->max_products);
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->sole();
    }
}
