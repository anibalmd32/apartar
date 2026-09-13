<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La matriz aprobada de [03 §5], sembrada (`REQ-SUB-001` RB-3).
 *
 * Los números están escritos otra vez aquí, a mano, y la duplicación es el
 * punto: son **dos transcripciones independientes** de la misma tabla de la
 * especificación. Un test que leyera la matriz del propio seeder comprobaría
 * que el seeder se parece a sí mismo, y pasaría en verde con un 300 convertido
 * en 30.
 */
final class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los tres planes de la matriz, fila a fila.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function matrix(): array
    {
        return [
            'free' => ['free', [
                'name' => 'Free',
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
                // 5 GB.
                'max_storage_mb' => 5120,
                'max_payment_accounts' => 5,
            ]],
            'business' => ['business', [
                'name' => 'Business',
                'position' => 3,
                'max_users' => 15,
                'max_catalogs' => 50,
                'max_products' => 2000,
                'max_products_per_catalog' => 500,
                'max_active_layaways' => 2000,
                // 25 GB.
                'max_storage_mb' => 25600,
                'max_payment_accounts' => 15,
            ]],
        ];
    }

    /**
     * Funciones de cada plan: las que **sí** incluye.
     *
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

    /**
     * El sello «Hecho con Apartar» se ve en Free y se oculta en los de pago, y
     * no hay una columna que lo diga: sale de no tener `branding`
     * (`REQ-CAT-022` RB-5).
     */
    public function test_the_platform_badge_follows_branding(): void
    {
        $this->assertTrue($this->plan('free')->showsPlatformBadge());
        $this->assertFalse($this->plan('pro')->showsPlatformBadge());
        $this->assertFalse($this->plan('business')->showsPlatformBadge());
    }

    /**
     * Las dos cifras de la matriz que no son techos de consumo. En chat,
     * `null` es ilimitado: Pro y Business muestran el historial entero.
     */
    public function test_it_seeds_the_numeric_allowances(): void
    {
        $this->assertSame(30, $this->plan('free')->allowance(PlanAllowance::ChatHistoryDays));
        $this->assertNull($this->plan('pro')->allowance(PlanAllowance::ChatHistoryDays));
        $this->assertNull($this->plan('business')->allowance(PlanAllowance::ChatHistoryDays));

        $this->assertSame(30, $this->plan('free')->allowance(PlanAllowance::TelemetryRetentionDays));
        $this->assertSame(180, $this->plan('pro')->allowance(PlanAllowance::TelemetryRetentionDays));
        $this->assertSame(365, $this->plan('business')->allowance(PlanAllowance::TelemetryRetentionDays));
    }

    /**
     * RB-4 y [03 §4.6]: los suscriptores de catálogo son ilimitados en los tres
     * planes, y por eso no hay ninguna columna ni función que los mencione.
     */
    public function test_no_plan_limits_catalog_subscribers(): void
    {
        foreach (Plan::query()->get() as $plan) {
            $this->assertArrayNotHasKey('max_subscribers', $plan->getAttributes());
            $this->assertArrayNotHasKey('subscribers', (array) $plan->features);
        }
    }

    /**
     * Los tres son públicos: no hay ninguno oculto en la matriz aprobada. El
     * mecanismo de `is_public` existe para precios negociados (RB-6).
     */
    public function test_the_three_plans_are_public(): void
    {
        $this->assertSame(3, Plan::query()->public()->count());
    }

    /**
     * SP-5 · sembrar dos veces no duplica ni cambia nada.
     */
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

        // Y son las mismas filas, no unas nuevas con los mismos datos.
        $this->assertEquals($created, $this->plan('free')->created_at);
    }

    /**
     * Y corrige una deriva: si alguien toca un límite a mano, el siguiente
     * sembrado devuelve la matriz aprobada.
     */
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
