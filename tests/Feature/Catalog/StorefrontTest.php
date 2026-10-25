<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Support\CatalogShareLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbour = $this->neighbourOrganization(['storefront_enabled' => true]);
        $this->subscribe($this->neighbour, Plan::factory()->create());
        $this->withinOrganization($this->neighbour, fn () => Catalog::factory()->published()->create(['name' => 'De la vecina']));

        $this->organization = Organization::factory()->create(['storefront_enabled' => true]);
        $this->subscribe($this->organization, Plan::factory()->create());
    }

    public function test_it_lists_only_the_listed_catalogs_of_this_organization(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            Catalog::factory()->published()->create(['name' => 'Listado', 'slug' => 'listado', 'published_at' => now()->subDays(2)]);
            Catalog::factory()->published()->create(['name' => 'Más reciente', 'slug' => 'mas-reciente', 'published_at' => now()->subDay()]);
            Catalog::factory()->published()->create(['name' => 'Solo por enlace', 'is_listed' => false]);
            Catalog::factory()->create(['name' => 'Borrador', 'published_at' => null]);
            Catalog::factory()->published()->create(['name' => 'Caducado', 'published_at' => now()->subMonth(), 'expires_at' => now()->subDay()]);
        });

        $this->visit($this->organization)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catalog/OrganizationStorefront')
            ->where('business.name', $this->organization->name)
            ->has('catalogs', 2)
            ->where('catalogs.0.name', 'Más reciente')
            ->where('catalogs.0.url', CatalogShareLink::of('mas-reciente'))
            ->where('catalogs.1.name', 'Listado'));
    }

    public function test_the_storefront_tells_the_business_profile(): void
    {
        $this->organization->forceFill([
            'tagline' => 'Bolsos y calzado · Caracas',
            'description' => 'Aparta con una inicial.',
            'address' => 'C.C. Sambil, Caracas',
            'phone' => '0414-555-1234',
        ])->save();

        $this->visit($this->organization)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('business.tagline', 'Bolsos y calzado · Caracas')
            ->where('profile.description', 'Aparta con una inicial.')
            ->where('profile.address', 'C.C. Sambil, Caracas')
            ->where('profile.phone', '0414-555-1234'));
    }

    public function test_a_switched_off_storefront_is_indistinguishable_from_an_unknown_slug(): void
    {
        $this->organization->update(['storefront_enabled' => false]);
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->published()->create());

        $off = $this->visit($this->organization);
        $this->withoutOrganization();
        $unknown = $this->get('/un-negocio-que-no-existe');

        $this->assertSame(404, $off->getStatusCode());
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame((string) $unknown->getContent(), (string) $off->getContent());
    }

    public function test_a_storefront_that_is_on_but_empty_is_a_page(): void
    {
        $this->visit($this->organization)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catalog/OrganizationStorefront')
            ->where('catalogs', []));
    }

    public function test_a_suspended_organization_shows_an_empty_storefront(): void
    {
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->create(['published_at' => null]));
        $this->organization->update(['status' => 'suspended', 'suspended_at' => now()]);

        $this->visit($this->organization)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('catalogs', []));
    }

    public function test_each_catalog_tells_its_visible_products(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            $catalog = Catalog::factory()->published()->create(['name' => 'Otoño']);
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);
            $catalog->products()->attach(Product::factory()->create(['published_at' => null]), ['position' => 2]);
        });

        $this->visit($this->organization)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('catalogs.0.name', 'Otoño')
            ->where('catalogs.0.productsCount', 1));
    }

    public function test_the_free_plan_keeps_the_brand_unapplied_and_shows_the_badge(): void
    {
        $this->organization->update(['brand_color' => '#123456']);

        $this->visit($this->organization)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('showsPlatformBadge', true)
            ->where('business.brandingEnabled', false)
            ->where('business.brandColor', '#123456'));
    }

    public function test_the_branding_feature_applies_the_brand_and_hides_the_badge(): void
    {
        Subscription::withoutTenantScope()->where('organization_id', $this->organization->getKey())->delete();
        $this->subscribe($this->organization, Plan::factory()->withEveryFeature()->create());

        $this->visit($this->organization)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('showsPlatformBadge', false)
            ->where('business.brandingEnabled', true));
    }

    public function test_the_storefront_does_not_count_nor_query_per_catalog(): void
    {
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->published()->create());
        $this->visit($this->organization);

        $few = $this->queries();
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->published()->count(4)->create());
        $many = $this->queries();

        $this->assertSame(count($few), count($many));
        $this->assertSame([], array_values(array_filter(
            $many,
            fn (string $sql): bool => str_contains(strtolower($sql), 'count(') && ! str_contains($sql, 'visible_products_count'),
        )));
    }

    private function subscribe(Organization $organization, Plan $plan): void
    {
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($plan)->create());
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visit(Organization $organization): TestResponse
    {
        $this->withoutOrganization();

        return $this->get('/'.$organization->slug);
    }

    /**
     * @return list<string>
     */
    private function queries(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit($this->organization)->assertOk();
        $queries = array_map(fn (array $query): string => (string) $query['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
