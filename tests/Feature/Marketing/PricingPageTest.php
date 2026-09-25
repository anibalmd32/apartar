<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Subscription\PlanFeature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * `/precios`, renderizado desde `plans` (`REQ-SUB-001` RB-8).
 */
final class PricingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /**
     * RB-8 · los tres planes de la matriz, en su orden, con sus límites
     * **leídos de la base**.
     */
    public function test_it_renders_the_three_public_plans_from_the_database(): void
    {
        $this->get('/precios')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('marketing/Pricing')
                ->has('plans', 3)
                ->where('plans.0.code', 'free')
                ->where('plans.1.code', 'pro')
                ->where('plans.2.code', 'business')
                ->where('plans.0.limits.0', ['key' => 'users', 'label' => 'usuario', 'limit' => 1])
                ->where('plans.0.limits.3', ['key' => 'products_per_catalog', 'label' => 'productos por catálogo', 'limit' => 20])
                ->where('plans.2.limits.2', ['key' => 'products', 'label' => 'productos', 'limit' => 2000])
                ->where('plans.1.limits.5', ['key' => 'storage_mb', 'label' => 'MB de almacenamiento', 'limit' => 5120]));
    }

    /**
     * Y la prueba de que lo lee de verdad: un `UPDATE` sobre `plans` se ve en
     * la siguiente visita, sin tocar código (RB-1).
     */
    public function test_a_change_in_the_table_is_what_the_page_shows(): void
    {
        Plan::query()->where('code', 'pro')->update(['max_users' => 7, 'name' => 'Pro renombrado']);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.1.name', 'Pro renombrado')
            ->where('plans.1.limits.0.limit', 7));
    }

    /**
     * SP-2 · `NULL` llega como `null`, que la pantalla escribe «ilimitado»
     * con `formatLimit()`. Nunca `0`.
     */
    public function test_a_null_limit_travels_as_unlimited_and_never_as_zero(): void
    {
        Plan::query()->where('code', 'business')->update(['max_catalogs' => null, 'max_products_per_catalog' => null]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.2.limits.1', ['key' => 'catalogs', 'label' => 'catálogos publicados', 'limit' => null])
            ->where('plans.2.limits.3', ['key' => 'products_per_catalog', 'label' => 'productos por catálogo', 'limit' => null]));
    }

    /**
     * RB-6 y SP-3 · un plan negociado no sale, y sus organizaciones siguen en
     * él exactamente igual.
     */
    public function test_a_hidden_plan_is_not_offered_and_its_organizations_stay_on_it(): void
    {
        $negotiated = Plan::factory()->hidden()->create(['name' => 'Negociado', 'position' => 0]);
        $organization = Organization::factory()->create();
        $subscription = $this->withinOrganization($organization, fn (): Subscription => Subscription::factory()->forPlan($negotiated)->create());

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('plans', 3)
            ->where('plans.0.code', 'free'));

        $this->assertSame(
            (int) $negotiated->getKey(),
            (int) $this->withinOrganization($organization, fn () => $subscription->fresh()?->plan_id),
        );
    }

    /**
     * SP-1 · un plan sin `features` es un plan sin funciones de pago: lista
     * vacía, sin error. Y el que las tiene las nombra.
     */
    public function test_a_plan_without_features_is_a_plan_without_paid_functions(): void
    {
        Plan::query()->where('code', 'free')->update(['features' => null]);

        $this->get('/precios')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.features', [])
            ->where('plans.1.features', fn (Collection $features): bool => $features->count() === 5)
            ->where('plans.2.features', fn (Collection $features): bool => $features->contains('Dominio propio')));
    }

    /** Cada función del enum tiene su nombre para esta página. */
    public function test_every_feature_has_a_name(): void
    {
        Plan::factory()->withEveryFeature()->create(['position' => 99]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.3.features', fn (Collection $features): bool => $features->count() === count(PlanFeature::cases())));
    }

    /** Una sola consulta de la página: la de los planes públicos. */
    public function test_it_reads_the_plans_in_one_query(): void
    {
        DB::enableQueryLog();

        $this->get('/precios')->assertOk();

        $plans = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains((string) $query['query'], '`plans`'));
        $this->assertCount(1, $plans);
    }

    /**
     * **`REQ-SUB-001` RB-3b, marcado al revés.** Hoy los planes de pago están
     * a `0` porque su precio está sin decidir, y la página, hecha bien, dice
     * «Gratis» en los tres. Este caso afirma ese estado para que **deje de
     * ser verdad en voz alta**: el día que se fijen los precios en el seeder
     * —que es donde vive la matriz— se pone rojo, y quien lo vea tiene que
     * revisar qué enseña `/precios`.
     *
     * Limitación: un precio fijado **solo** con un `UPDATE` en producción no
     * pasa por aquí. Por eso el mensaje pide también llevar la cifra al
     * seeder, que es lo que mantiene la matriz cotejable.
     */
    public function test_the_paid_plans_are_still_unpriced(): void
    {
        $priced = Plan::query()->public()->whereIn('code', ['pro', 'business'])->where('price_cents', '!=', 0)->pluck('code')->all();

        $this->assertSame([], $priced, 'Los precios de Pro y Business ya están fijados (REQ-SUB-001 RB-3b, REQ-SUB-006). '
            .'Revisa qué enseña /precios en la fila del precio y cómo lo dice —el periodo, la moneda, si hace falta '
            .'aclarar la facturación— y sustituye este caso por uno que afirme el precio de verdad.');

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.priceLabel', 'Gratis')
            ->where('plans.1.priceLabel', 'Gratis')
            ->where('plans.2.priceLabel', 'Gratis'));
    }

    /** Un precio distinto de cero se escribe con su cifra y su periodo. */
    public function test_a_price_is_written_with_its_period(): void
    {
        Plan::factory()->create(['price_cents' => 2_900, 'interval' => 'monthly', 'position' => 99]);
        Plan::factory()->create(['price_cents' => 1_500, 'interval' => 'weekly', 'position' => 100]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.3.priceLabel', '$29.00 al mes')
            ->where('plans.3.isFree', false)
            ->where('plans.4.priceLabel', '$15.00'));
    }

    public function test_it_opens_for_a_visitor_without_a_session(): void
    {
        $this->get('/precios')->assertOk();
        $this->assertGuest();
    }
}
