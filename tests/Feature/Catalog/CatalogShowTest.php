<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Organization\SuspendOrganizationAction;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Support\CatalogCursor;
use App\Support\CatalogShareLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogShowTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->subscribe($neighbour, Plan::factory()->create());
        $this->withinOrganization($neighbour, fn () => $this->attach(
            Catalog::factory()->published()->create(),
            Product::factory()->published()->count(3)->create()->all(),
        ));

        $this->organization = Organization::factory()->create();
        $this->subscribe($this->organization, Plan::factory()->create());
    }

    public function test_a_listed_catalog_opens_without_a_session(): void
    {
        $catalog = $this->catalogWith(2);

        $this->visit($catalog)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('catalog/CatalogShow')
                ->where('catalog.slug', $catalog->slug)
                ->where('catalog.name', $catalog->name)
                ->where('catalog.business.name', $this->organization->name)
                ->has('products.data', 2));

        $this->assertGuest();
    }

    public function test_an_unlisted_catalog_is_reachable_by_its_link(): void
    {
        $catalog = $this->catalogWith(1, ['is_listed' => false]);

        $this->visit($catalog)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', 1));
    }

    public function test_a_draft_catalog_is_not_found_even_knowing_its_slug(): void
    {
        $catalog = $this->catalogWith(1, ['published_at' => null]);

        $this->visit($catalog)->assertNotFound();
    }

    public function test_a_draft_with_a_past_expiration_is_not_found_and_not_gone(): void
    {
        $catalog = $this->catalogWith(1, ['published_at' => null, 'expires_at' => now()->subDay()]);

        $this->visit($catalog)->assertNotFound();
    }

    public function test_an_expired_catalog_is_gone_with_a_page_that_explains_it(): void
    {
        $catalog = $this->catalogWith(1, ['published_at' => now()->subMonth(), 'expires_at' => now()->subDay()]);

        $this->visit($catalog)
            ->assertStatus(410)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('catalog/CatalogExpired')
                ->where('catalogName', $catalog->name)
                ->where('businessName', $this->organization->name)
                ->where('endedOn', $catalog->expires_at?->setTimezone($this->organization->timezone)->toDateString()));
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->get('/c/no-existe-este-catalogo')->assertNotFound();
    }

    public function test_a_suspended_organizations_catalog_is_not_found(): void
    {
        $catalog = $this->catalogWith(1);
        $this->visit($catalog)->assertOk();

        app(SuspendOrganizationAction::class)->handle($this->organization);
        $this->withoutOrganization();

        $this->visit($catalog)->assertNotFound();
    }

    public function test_the_gone_page_links_the_storefront_only_when_it_is_on(): void
    {
        $catalog = $this->catalogWith(1, ['published_at' => now()->subMonth(), 'expires_at' => now()->subDay()]);

        $this->visit($catalog)->assertStatus(410)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('storefrontUrl', null));

        $this->organization->update(['storefront_enabled' => true]);

        $this->visit($catalog)->assertStatus(410)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('storefrontUrl', CatalogShareLink::storefrontOf($this->organization->slug)));

        $this->withoutOrganization();
        $this->get(parse_url(CatalogShareLink::storefrontOf($this->organization->slug), PHP_URL_PATH) ?: '/')->assertOk();
    }

    public function test_unpublished_products_are_left_out_and_keep_their_place(): void
    {
        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            $catalog = Catalog::factory()->published()->create();

            $this->attach($catalog, [
                Product::factory()->published()->create(['name' => 'Visible']),
                Product::factory()->create(['name' => 'Borrador']),
                Product::factory()->archived()->create(['name' => 'Archivado']),
                Product::factory()->scheduled()->create(['name' => 'Programado']),
            ]);

            return $catalog;
        });

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Visible'));

        $this->assertSame(4, DB::table('catalog_product')->where('catalog_id', $catalog->getKey())->count());
    }

    public function test_a_catalog_with_nothing_visible_renders_empty(): void
    {
        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            $catalog = Catalog::factory()->published()->create();
            $this->attach($catalog, [Product::factory()->archived()->create()]);

            return $catalog;
        });

        $this->visit($catalog)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data', 0)
            ->where('products.nextCursor', null));
    }

    public function test_the_grid_follows_the_curated_order(): void
    {
        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            $catalog = Catalog::factory()->published()->create();
            $products = Product::factory()->published()->count(3)->sequence(
                ['name' => 'Primero creado'],
                ['name' => 'Segundo creado'],
                ['name' => 'Tercero creado'],
            )->create()->all();

            $this->attach($catalog, array_reverse($products));

            return $catalog;
        });

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.name', 'Tercero creado')
            ->where('products.data.1.name', 'Segundo creado')
            ->where('products.data.2.name', 'Primero creado'));
    }

    public function test_it_paginates_by_cursor_without_counting(): void
    {
        $catalog = $this->catalogWith(CatalogCursor::PER_PAGE + 1);

        DB::enableQueryLog();

        $first = $this->props($this->visit($catalog));

        $this->assertCount(CatalogCursor::PER_PAGE, $first['products']['data']);
        $this->assertIsString($first['products']['nextCursor']);

        $second = $this->props($this->visit($catalog, ['cursor' => $first['products']['nextCursor']]));

        $this->assertCount(1, $second['products']['data']);
        $this->assertNull($second['products']['nextCursor']);

        $slugs = array_merge(
            array_column($first['products']['data'], 'slug'),
            array_column($second['products']['data'], 'slug'),
        );
        $this->assertCount(CatalogCursor::PER_PAGE + 1, array_unique($slugs));

        $counts = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'count(')
                && ! preg_match('/^select `(categories|tags)`\.\*, \(select count\(\*\)/', (string) $query['query']),
        );
        $this->assertSame([], array_values($counts), 'La parrilla no puede contar filas.');
    }

    public function test_the_grid_does_not_query_per_product(): void
    {
        $small = $this->catalogWith(2);
        $large = $this->catalogWith(CatalogCursor::PER_PAGE);

        $this->queriesFor($small);

        $this->assertSame($this->queriesFor($small), $this->queriesFor($large));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tamperedCursors(): iterable
    {
        yield 'basura' => ['esto-no-es-un-cursor'];
        yield 'un número en JSON' => [rtrim(strtr(base64_encode('5'), '+/', '-_'), '=')];
        yield 'sin dirección' => [rtrim(strtr(base64_encode('{"products.id":3}'), '+/', '-_'), '=')];
        yield 'claves inventadas' => [rtrim(strtr(base64_encode('{"price_cents":1,"_pointsToNextItems":true}'), '+/', '-_'), '=')];
        yield 'posición no numérica' => [rtrim(strtr(base64_encode(
            '{"catalog_product.position":"1 or 1=1","products.id":1,"_pointsToNextItems":true}',
        ), '+/', '-_'), '=')];
    }

    #[DataProvider('tamperedCursors')]
    public function test_a_tampered_cursor_restarts_at_the_first_page(string $cursor): void
    {
        $catalog = $this->catalogWith(CatalogCursor::PER_PAGE + 1);

        $this->visit($catalog, ['cursor' => $cursor])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', CatalogCursor::PER_PAGE));
    }

    public function test_the_cards_show_price_down_payment_and_installment_in_both_currencies(): void
    {
        ExchangeRate::factory()->create(['rate' => '42.50000000']);
        $catalog = $this->catalogWith(1);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.price.usd', '$250,00')
            ->where('products.data.0.price.ves', 'Bs. 10.625,00')
            ->where('products.data.0.downPayment.ves', 'Bs. 2.125,00')
            ->where('products.data.0.installmentAmount.usd', '$50,00')
            ->where('products.data.0.installmentAmount.ves', 'Bs. 2.125,00')
            ->where('products.data.0.installmentsCount', 4)
            ->where('products.data.0.inStock', true)
            ->where('currency.rate.rate', 42.5)
            ->where('currency.timezone', $this->organization->timezone));
    }

    public function test_without_any_rate_only_dollars_travel(): void
    {
        $catalog = $this->catalogWith(1);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.price.usd', '$250,00')
            ->where('products.data.0.price.ves', null)
            ->where('products.data.0.downPayment.ves', null)
            ->where('products.data.0.installmentAmount.ves', null)
            ->where('currency.rate.rate', null)
            ->where('currency.rate.publishedAt', null));
    }

    public function test_an_uneven_plan_names_its_last_installment(): void
    {
        $catalog = $this->catalogWith(1, [], ['price_cents' => 10_000, 'down_payment_cents' => 0, 'installments_count' => 3]);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.installmentAmount.usd', '$33,33')
            ->where('products.data.0.lastInstallmentAmount.usd', '$33,34'));
    }

    public function test_an_out_of_stock_product_says_so_without_its_stock(): void
    {
        $catalog = $this->catalogWith(1, [], ['stock' => 0]);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.inStock', false)
            ->missing('products.data.0.stock'));
    }

    public function test_the_free_plan_shows_the_platform_badge_and_does_not_apply_the_brand(): void
    {
        $catalog = $this->catalogWith(1);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('catalog.showsPlatformBadge', true)
            ->where('catalog.business.brandingEnabled', false));
    }

    public function test_the_branding_feature_hides_the_badge(): void
    {
        Subscription::withoutTenantScope()->where('organization_id', $this->organization->getKey())->delete();
        $this->subscribe($this->organization, Plan::factory()->withEveryFeature()->create());
        $catalog = $this->catalogWith(1);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('catalog.showsPlatformBadge', false)
            ->where('catalog.business.brandingEnabled', true));
    }

    public function test_the_open_graph_tags_are_in_the_server_html(): void
    {
        $catalog = $this->catalogWith(1, [
            'name' => 'Ofertas de septiembre',
            'description' => 'Taladros y sierras con inicial de 20 %.',
            'cover_path' => 'covers/ofertas.jpg',
        ]);

        $html = (string) $this->visit($catalog)->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Ofertas de septiembre · '.e($this->organization->name).'">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Taladros y sierras con inicial de 20 %.">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.CatalogShareLink::of($catalog->slug).'">', $html);
        $this->assertStringContainsString('covers/ofertas.jpg', $html);
        $this->assertStringContainsString('<meta property="og:image"', $html);
    }

    public function test_a_catalog_without_description_still_previews_with_the_business(): void
    {
        $catalog = $this->catalogWith(1);

        $html = (string) $this->visit($catalog)->getContent();

        $this->assertStringContainsString('<meta property="og:description" content="Catálogo de '.e($this->organization->name), $html);
    }

    public function test_the_view_carries_no_subscription_and_no_count(): void
    {
        $catalog = $this->catalogWith(1);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('catalog.subscription')
            ->missing('catalog.productsCount'));
    }

    public function test_the_page_tells_how_many_products_and_followers(): void
    {
        $catalog = $this->catalogWith(3);
        $this->withinOrganization($this->organization, fn () => Product::factory()->create(['published_at' => null]));
        CatalogSubscription::query()->create(['account_id' => Account::factory()->create()->getKey(), 'catalog_id' => $catalog->getKey(), 'subscribed_at' => now()]);

        $this->visit($catalog)->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('stats')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('stats.products', 3)
                ->where('stats.followers', 1)));
    }

    public function test_the_share_link_is_the_public_route(): void
    {
        $this->assertSame(url('/c/ofertas-septiembre'), CatalogShareLink::of('ofertas-septiembre'));
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $product
     */
    private function catalogWith(int $products, array $catalog = [], array $product = []): Catalog
    {
        return $this->withinOrganization($this->organization, function () use ($products, $catalog, $product): Catalog {
            $model = Catalog::factory()->published()->create($catalog);

            $this->attach($model, Product::factory()->published()->count($products)->create($product)->all());

            return $model;
        });
    }

    /**
     * @param  list<Product>  $products
     */
    private function attach(Catalog $catalog, array $products): void
    {
        foreach ($products as $index => $product) {
            $catalog->products()->attach($product, ['position' => $index + 1]);
        }
    }

    private function subscribe(Organization $organization, Plan $plan): void
    {
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($plan)->create());
    }

    /**
     * @param  array<string, string>  $query
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visit(Catalog $catalog, array $query = []): TestResponse
    {
        $this->withoutOrganization();

        return $this->get('/c/'.$catalog->slug.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $props = [];
        $response->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    private function queriesFor(Catalog $catalog): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->visit($catalog)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
