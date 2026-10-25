<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PublicCatalogIsolationTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $ferreteria;

    private Organization $boutique;

    private Catalog $tools;

    private Catalog $clothes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ferreteria = Organization::factory()->create(['name' => 'Ferretería Oriente', 'timezone' => 'America/Caracas']);
        $this->boutique = $this->neighbourOrganization(['name' => 'Boutique Andina', 'timezone' => 'America/Bogota']);

        $this->tools = $this->catalogOf($this->ferreteria, 'herramientas', ['Taladro', 'Sierra']);
        $this->clothes = $this->catalogOf($this->boutique, 'ropa', ['Chaqueta', 'Bufanda', 'Gorro']);
    }

    public function test_each_link_fixes_its_own_business_and_shows_only_its_products(): void
    {
        $this->visit($this->tools)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('catalog.business.name', 'Ferretería Oriente')
            ->where('currency.timezone', 'America/Caracas')
            ->has('products.data', 2)
            ->where('products.data.0.name', 'Taladro')
            ->where('products.data.1.name', 'Sierra'));

        $this->assertTrue($this->ferreteria->is(app(TenantContext::class)->organization()));

        $this->visit($this->clothes)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('catalog.business.name', 'Boutique Andina')
            ->where('currency.timezone', 'America/Bogota')
            ->has('products.data', 3));

        $this->assertTrue($this->boutique->is(app(TenantContext::class)->organization()));
    }

    public function test_nothing_of_the_other_business_reaches_the_page(): void
    {
        $body = (string) $this->visit($this->tools)->getContent();

        foreach (['Boutique Andina', 'Chaqueta', 'Bufanda', 'Gorro', 'Categoría de ropa', 'ropa-'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $body);
        }
    }

    public function test_a_cross_tenant_pivot_row_is_never_served(): void
    {
        $foreign = $this->withinOrganization($this->boutique, fn (): Product => Product::query()->firstOrFail());

        DB::table('catalog_product')->insert([
            'catalog_id' => $this->tools->getKey(),
            'product_id' => $foreign->getKey(),
            'position' => 0,
        ]);

        $this->visit($this->tools)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data', 2)
            ->where('products.data.0.name', 'Taladro'));
    }

    public function test_the_public_props_carry_no_internal_identifiers(): void
    {
        $props = [];
        $this->visit($this->tools)->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        $keys = $this->keysOf(['catalog' => $props['catalog'], 'products' => $props['products']]);

        foreach (['id', 'organization_id', 'organizationId', 'account_id', 'accountId', 'sku', 'stock'] as $internal) {
            $this->assertNotContains($internal, $keys, "La vista pública expone «{$internal}».");
        }
    }

    public function test_each_catalog_projects_with_its_business_rate(): void
    {
        ExchangeRate::factory()->create(['rate' => '40.00000000']);
        ExchangeRate::factory()->parallel()->create(['rate' => '50.00000000']);

        $this->withinOrganization($this->ferreteria, fn () => OrganizationSetting::factory()
            ->forKey(SettingKey::ExchangeRateSource, RateSource::Parallel->value)
            ->create());

        $this->visit($this->tools)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currency.rate.source', 'parallel')
            ->where('products.data.0.price.ves', 'Bs. 12.500,00'));

        $this->visit($this->clothes)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currency.rate.source', 'bcv')
            ->where('products.data.0.price.ves', 'Bs. 10.000,00'));
    }

    public function test_the_middleware_refuses_a_route_without_the_raw_slug(): void
    {
        Route::middleware(['web', 'catalog-tenant'])->get('__gate/publica-sin-catalogo', fn (): string => 'ok');

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);

        $this->get('/__gate/publica-sin-catalogo');
    }

    /**
     * @param  list<string>  $names
     */
    private function catalogOf(Organization $organization, string $prefix, array $names): Catalog
    {
        return $this->withinOrganization($organization, function () use ($prefix, $names): Catalog {
            Subscription::factory()->forPlan(Plan::factory()->create())->create();

            $category = Category::factory()->create(['name' => 'Categoría de '.$prefix]);
            $catalog = Catalog::factory()->published()->create(['slug' => $prefix.'-'.strtolower((string) str()->random(6))]);

            foreach ($names as $index => $name) {
                $product = Product::factory()->published()->inCategory($category)->create(['name' => $name]);
                $catalog->products()->attach($product, ['position' => $index + 1]);
            }

            return $catalog;
        });
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visit(Catalog $catalog): TestResponse
    {
        $this->withoutOrganization();

        return $this->get('/c/'.$catalog->slug);
    }

    /**
     * @param  array<mixed>  $tree
     * @return list<string>
     */
    private function keysOf(array $tree): array
    {
        $keys = [];

        foreach ($tree as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = [...$keys, ...$this->keysOf($value)];
            }
        }

        return $keys;
    }
}
