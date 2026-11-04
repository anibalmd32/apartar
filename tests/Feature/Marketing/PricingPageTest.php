<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Subscription\PlanFeature;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PricingPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_while_the_paid_plans_are_hidden_only_the_free_one_is_offered(): void
    {
        $this->get('/precios')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('plans', 1)
                ->where('plans.0.code', 'free'));
    }

    public function test_it_renders_the_three_public_plans_from_the_database(): void
    {
        Plan::query()->update(['is_public' => true]);
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
                ->where('plans.0.limits.5', ['key' => 'storage_mb', 'label' => 'MB de almacenamiento', 'limit' => 200])
                ->where('plans.1.limits.5', ['key' => 'storage_mb', 'label' => 'GB de almacenamiento', 'limit' => 5])
                ->where('plans.2.limits.5', ['key' => 'storage_mb', 'label' => 'GB de almacenamiento', 'limit' => 25]));
    }

    public function test_each_plan_travels_with_its_price_in_cents_and_the_page_with_the_latest_bcv_rate(): void
    {
        Plan::query()->update(['is_public' => true]);
        ExchangeRate::query()->delete();
        ExchangeRate::factory()->create(['rate' => '880.00000000', 'published_at' => CarbonImmutable::parse('2026-11-02 00:00:00')]);
        ExchangeRate::factory()->create(['rate' => '893.22000000', 'published_at' => CarbonImmutable::parse('2026-11-04 00:00:00')]);
        ExchangeRate::factory()->parallel()->create(['rate' => '1000.00000000', 'published_at' => CarbonImmutable::parse('2026-11-04 17:00:00')]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.priceCents', 0)
            ->where('plans.1.priceCents', Plan::query()->where('code', 'pro')->value('price_cents'))
            ->where('rate', ['date' => '2026-11-04', 'rate' => '893.22000000']));
    }

    public function test_without_a_saved_rate_the_page_offers_no_bolivar_equivalent(): void
    {
        ExchangeRate::query()->delete();

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page->where('rate', null));
    }

    public function test_a_change_in_the_table_is_what_the_page_shows(): void
    {
        Plan::query()->update(['is_public' => true]);
        Plan::query()->where('code', 'pro')->update(['max_users' => 7, 'name' => 'Pro renombrado']);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.1.name', 'Pro renombrado')
            ->where('plans.1.limits.0.limit', 7));
    }

    public function test_a_null_limit_travels_as_unlimited_and_never_as_zero(): void
    {
        Plan::query()->update(['is_public' => true]);
        Plan::query()->where('code', 'business')->update(['max_catalogs' => null, 'max_products_per_catalog' => null]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.2.limits.1', ['key' => 'catalogs', 'label' => 'catálogos publicados', 'limit' => null])
            ->where('plans.2.limits.3', ['key' => 'products_per_catalog', 'label' => 'productos por catálogo', 'limit' => null]));
    }

    public function test_a_hidden_plan_is_not_offered_and_its_organizations_stay_on_it(): void
    {
        Plan::query()->update(['is_public' => true]);
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

    public function test_a_plan_without_features_is_a_plan_without_paid_functions(): void
    {
        Plan::query()->update(['is_public' => true]);
        Plan::query()->where('code', 'free')->update(['features' => null]);

        $this->get('/precios')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.features', [])
            ->where('plans.1.features', fn (Collection $features): bool => $features->count() === 5)
            ->where('plans.2.features', fn (Collection $features): bool => $features->contains('Dominio propio')));
    }

    public function test_every_feature_has_a_name(): void
    {
        Plan::query()->update(['is_public' => true]);
        Plan::factory()->withEveryFeature()->create(['position' => 99]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.3.features', fn (Collection $features): bool => $features->count() === count(PlanFeature::cases())));
    }

    public function test_it_reads_the_plans_in_one_query(): void
    {
        Plan::query()->update(['is_public' => true]);
        DB::enableQueryLog();

        $this->get('/precios')->assertOk();

        $plans = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains((string) $query['query'], '`plans`'));
        $this->assertCount(1, $plans);
    }

    public function test_the_paid_plans_show_their_monthly_price(): void
    {
        Plan::query()->update(['is_public' => true]);
        $this->assertSame(
            ['free' => 0, 'pro' => 1_500, 'business' => 3_900],
            Plan::query()->public()->orderBy('position')->pluck('price_cents', 'code')->map(fn (mixed $cents): int => (int) $cents)->all(),
        );

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.price', '$0')
            ->where('plans.0.period', 'para siempre')
            ->where('plans.1.price', '$15')
            ->where('plans.1.period', 'al mes')
            ->where('plans.1.isFree', false)
            ->where('plans.2.price', '$39'));
    }

    public function test_a_price_is_written_with_its_period(): void
    {
        Plan::query()->update(['is_public' => true]);
        Plan::factory()->create(['price_cents' => 2_900, 'interval' => 'monthly', 'position' => 99]);
        Plan::factory()->create(['price_cents' => 1_550, 'interval' => 'weekly', 'position' => 100]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.3.price', '$29')
            ->where('plans.3.period', 'al mes')
            ->where('plans.3.isFree', false)
            ->where('plans.4.price', '$15,50')
            ->where('plans.4.period', ''));
    }

    public function test_a_storage_that_is_not_whole_gigabytes_stays_in_megabytes(): void
    {
        Plan::query()->update(['is_public' => true]);
        Plan::query()->where('code', 'pro')->update(['max_storage_mb' => 1500]);

        $this->get('/precios')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.1.limits.5', ['key' => 'storage_mb', 'label' => 'MB de almacenamiento', 'limit' => 1500]));
    }

    public function test_it_opens_for_a_visitor_without_a_session(): void
    {
        Plan::query()->update(['is_public' => true]);
        $this->get('/precios')->assertOk();
        $this->assertGuest();
    }
}
