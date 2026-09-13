<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cómo se leen los límites y las funciones de un plan (`REQ-SUB-001` RB-2).
 *
 * El punto entero de estos métodos es que nadie tenga que saber qué plan es:
 * se pregunta por la métrica y se responde con el techo. Es lo que permite que
 * subir el tope de productos de Free sea un `UPDATE` y no un despliegue.
 */
final class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cada métrica lee su columna, y la lee de la fila.
     */
    public function test_it_reads_the_limit_of_each_metric_from_the_row(): void
    {
        $plan = Plan::factory()->create([
            'max_users' => 1,
            'max_catalogs' => 2,
            'max_products' => 3,
            'max_active_layaways' => 4,
            'max_storage_mb' => 5,
            'max_payment_accounts' => 6,
        ]);

        $this->assertSame(1, $plan->limitFor(UsageMetric::Users));
        $this->assertSame(2, $plan->limitFor(UsageMetric::Catalogs));
        $this->assertSame(3, $plan->limitFor(UsageMetric::Products));
        $this->assertSame(4, $plan->limitFor(UsageMetric::ActiveLayaways));
        $this->assertSame(5, $plan->limitFor(UsageMetric::StorageMb));
        $this->assertSame(6, $plan->limitFor(UsageMetric::PaymentAccounts));
    }

    /**
     * SP-2 · `null` es **ilimitado**, y tiene que llegar como `null` hasta
     * arriba. Un `0` mostrado donde va «ilimitado» convierte el mejor plan en
     * el peor.
     */
    public function test_a_null_limit_means_unlimited_and_never_zero(): void
    {
        $plan = Plan::factory()->unlimited()->create();

        foreach (UsageMetric::cases() as $metric) {
            $this->assertNull($plan->limitFor($metric), sprintf(
                'El techo de %s debería ser ilimitado y llegó como %s.',
                $metric->value,
                var_export($plan->limitFor($metric), true),
            ));
        }

        $this->assertNull($plan->maxProductsPerCatalog());
    }

    /**
     * Y un techo de cero sigue siendo un techo: no es lo mismo «no puedes
     * crear ninguno» que «crea los que quieras».
     */
    public function test_a_zero_limit_is_not_unlimited(): void
    {
        $plan = Plan::factory()->create(['max_catalogs' => 0]);

        $this->assertSame(0, $plan->limitFor(UsageMetric::Catalogs));
        $this->assertNotNull($plan->limitFor(UsageMetric::Catalogs));
    }

    /**
     * La séptima columna, la que no tiene métrica: se cuenta por catálogo y
     * los contadores son por organización.
     */
    public function test_the_per_catalog_cap_is_read_apart_from_the_metrics(): void
    {
        $plan = Plan::factory()->create(['max_products_per_catalog' => 20]);

        $this->assertSame(20, $plan->maxProductsPerCatalog());
    }

    public function test_it_answers_whether_the_plan_includes_a_feature(): void
    {
        $plan = Plan::factory()->create([
            'features' => PlanFeature::only([PlanFeature::Branding, PlanFeature::WebPush]),
        ]);

        $this->assertTrue($plan->hasFeature(PlanFeature::Branding));
        $this->assertTrue($plan->hasFeature(PlanFeature::WebPush));
        $this->assertFalse($plan->hasFeature(PlanFeature::CustomDomain));

        $this->assertSame(
            ['branding', 'web_push'],
            array_map(fn (PlanFeature $feature): string => $feature->value, $plan->enabledFeatures()),
        );
    }

    /**
     * SP-1 · un plan sin `features` es un plan sin funciones de pago, no un
     * error. La ausencia se lee como `false` en todas.
     */
    public function test_a_plan_without_features_has_none_instead_of_failing(): void
    {
        $plan = Plan::factory()->create(['features' => null]);

        foreach (PlanFeature::cases() as $feature) {
            $this->assertFalse($plan->hasFeature($feature));
        }

        $this->assertSame([], $plan->enabledFeatures());
        $this->assertNull($plan->allowance(PlanAllowance::ChatHistoryDays));
    }

    /**
     * El sello se deriva de `branding`: no hay forma de sembrar un plan con
     * marca propia y sello a la vez (`REQ-CAT-022` RB-5).
     */
    public function test_the_platform_badge_is_the_opposite_of_branding(): void
    {
        $branded = Plan::factory()->create([
            'features' => PlanFeature::only([PlanFeature::Branding]),
        ]);

        $plain = Plan::factory()->create(['features' => PlanFeature::only([])]);

        $this->assertFalse($branded->showsPlatformBadge());
        $this->assertTrue($plain->showsPlatformBadge());
    }

    /**
     * Las concesiones numéricas, con su propio `null` de ilimitado: en Pro el
     * historial de chat no tiene ventana.
     */
    public function test_it_reads_the_numeric_allowances(): void
    {
        $plan = Plan::factory()->create([
            'features' => PlanFeature::only([]) + [
                PlanAllowance::ChatHistoryDays->value => null,
                PlanAllowance::TelemetryRetentionDays->value => 180,
            ],
        ]);

        $this->assertNull($plan->allowance(PlanAllowance::ChatHistoryDays));
        $this->assertSame(180, $plan->allowance(PlanAllowance::TelemetryRetentionDays));
    }

    /**
     * RB-6 y SP-3 · un plan oculto sigue funcionando para quien ya está
     * dentro; solo desaparece del escaparate.
     */
    public function test_a_hidden_plan_disappears_from_the_public_listing_only(): void
    {
        $visible = Plan::factory()->create(['position' => 1]);
        $hidden = Plan::factory()->hidden()->create(['position' => 2]);

        $public = Plan::query()->public()->get();

        $this->assertTrue($public->contains($visible));
        $this->assertFalse($public->contains($hidden));

        $this->assertSame(5, $hidden->limitFor(UsageMetric::Users));
    }

    /**
     * El escaparate sale ordenado por `position`, que es el orden en que se
     * comparan los planes.
     */
    public function test_the_public_listing_comes_ordered_by_position(): void
    {
        Plan::factory()->create(['code' => 'tercero', 'position' => 3]);
        Plan::factory()->create(['code' => 'primero', 'position' => 1]);
        Plan::factory()->create(['code' => 'segundo', 'position' => 2]);

        $this->assertSame(
            ['primero', 'segundo', 'tercero'],
            Plan::query()->public()->pluck('code')->all(),
        );
    }
}
