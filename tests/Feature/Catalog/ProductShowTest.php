<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\UnavailableReason;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\ProductDetailResource;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\PlanLimitRejection;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * La ficha pública del producto (`REQ-CAT-003`).
 *
 * Todas las peticiones son **anónimas**, como las de la vista del catálogo: el
 * tenant sale del slug del catálogo (`ResolveCatalogTenant`) y el producto se
 * resuelve dentro de él (`Catalog::resolveChildRouteBinding()`).
 */
final class ProductShowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $neighbour;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        // REQ-QAX-003 RB-10: la vecina tiene un producto publicado con un slug
        // que aquí también existe, para que una fuga tenga algo que servir.
        $this->neighbour = $this->neighbourOrganization();
        $this->subscribe($this->neighbour);
        $this->withinOrganization($this->neighbour, fn () => $this->attach(
            Catalog::factory()->published()->create(),
            [$this->product('Taladro vecino', ['slug' => 'taladro-vecino'])],
        ));

        $this->organization = Organization::factory()->create();
        $this->subscribe($this->organization);
        $this->catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());
    }

    // ------------------------------------------------------------------
    // Los cuatro 404 y el 410 (RB-1, SP-1 a SP-3, SP-9)
    // ------------------------------------------------------------------

    public function test_a_published_product_of_this_catalog_opens_without_a_session(): void
    {
        $this->stock([$this->product('Taladro percutor')]);

        $this->visit('taladro-percutor')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catalog/ProductShow')
            ->where('catalog.slug', $this->catalog->slug)
            ->where('catalog.business.name', $this->organization->name)
            ->where('product.slug', 'taladro-percutor')
            ->where('product.catalogSlug', $this->catalog->slug)
            ->has('related'));

        $this->assertGuest();
    }

    /**
     * §6 · los cuatro motivos dan el mismo `404` **con el mismo cuerpo**: la
     * respuesta no dice si el producto está en otro catálogo, en borrador, si
     * su slug cambió o si es de otro negocio.
     */
    public function test_the_four_reasons_for_not_found_are_indistinguishable(): void
    {
        $this->stock([$this->product('Visible')]);
        $this->withinOrganization($this->organization, function (): void {
            $this->attach(Catalog::factory()->published()->create(), [$this->product('De otra campaña')]);
            $this->attach($this->catalog, [
                Product::factory()->create(['name' => 'Borrador', 'slug' => 'borrador']),
                Product::factory()->archived()->create(['name' => 'Archivado', 'slug' => 'archivado']),
                Product::factory()->scheduled()->create(['name' => 'Programado', 'slug' => 'programado']),
            ]);
        });
        $foreign = $this->withinOrganization($this->neighbour, fn (): int => (int) Product::query()->value('id'));
        DB::table('catalog_product')->insert(['catalog_id' => $this->catalog->getKey(), 'product_id' => $foreign, 'position' => 99]);

        $bodies = [];

        foreach ([
            'de otro catálogo (SP-1)' => 'de-otra-campana',
            'borrador (SP-2)' => 'borrador',
            'archivado (SP-2)' => 'archivado',
            'programado (SP-2)' => 'programado',
            'slug cambiado (SP-9)' => 'visible-antes',
            'de otra organización, enlazado a mano (fuga)' => 'taladro-vecino',
        ] as $case => $slug) {
            $response = $this->visit($slug);
            $this->assertSame(404, $response->getStatusCode(), $case);
            $bodies[$case] = (string) $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'Los 404 no pueden distinguirse entre sí.');
    }

    /**
     * SP-3 · el catálogo manda **antes** que el producto: caducado es `410`
     * exista o no el producto de la URL; en borrador, `404`.
     *
     * @return iterable<string, array{array<string, mixed>, string, int}>
     */
    public static function closedCatalogs(): iterable
    {
        $expired = ['published_at' => now()->subMonth(), 'expires_at' => now()->subDay()];

        yield 'caducado, producto válido' => [$expired, 'taladro', 410];
        yield 'caducado, producto que no existe' => [$expired, 'no-existe', 410];
        yield 'borrador, producto válido' => [['published_at' => null], 'taladro', 404];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[DataProvider('closedCatalogs')]
    public function test_the_catalog_decides_before_the_product(array $state, string $slug, int $status): void
    {
        $this->stock([$this->product('Taladro')]);
        $this->catalog->forceFill($state)->save();

        $this->visit($slug)->assertStatus($status);
    }

    // ------------------------------------------------------------------
    // Los importes (RB-2 a RB-4, SP-8)
    // ------------------------------------------------------------------

    /**
     * §5 · la cuota de la ficha **es** la de la card: los mismos campos, con
     * los mismos valores, para un reparto que deja residuo en la última.
     */
    public function test_the_detail_shows_exactly_the_amounts_of_the_card(): void
    {
        ExchangeRate::factory()->create(['rate' => '42.50000000']);
        $this->stock([$this->product('Taladro', ['price_cents' => 25_000, 'down_payment_cents' => 5_000, 'installments_count' => 3, 'compare_at_price_cents' => 30_000])]);

        $card = $this->props($this->visitCatalog())['products']['data'][0];
        $detail = $this->props($this->visit('taladro'))['product'];

        foreach (['price', 'compareAtPrice', 'downPayment', 'installmentAmount', 'lastInstallmentAmount', 'installmentsCount', 'frequencyDays', 'categoryName', 'tags', 'inStock', 'catalogSlug'] as $field) {
            $this->assertSame($card[$field], $detail[$field], $field);
        }

        $this->assertSame('$66.66', $detail['installmentAmount']['usd']);
        $this->assertSame('$66.68', $detail['lastInstallmentAmount']['usd']);
        $this->assertSame('Bs. 10.625,00', $detail['price']['ves']);
    }

    public function test_an_even_plan_does_not_name_a_last_installment(): void
    {
        $this->stock([$this->product('Taladro')]);

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.installmentAmount.usd', '$50.00')
            ->where('product.lastInstallmentAmount', null));
    }

    /** SP-8 · sin tasa, solo dólares, y el sello lo dice. */
    public function test_without_a_rate_only_dollars_travel(): void
    {
        $this->stock([$this->product('Taladro')]);

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.price.ves', null)
            ->where('product.installmentAmount.ves', null)
            ->where('currency.rate.rate', null));
    }

    /**
     * §8 · ni `business` dentro del producto ni `rate` en las props: el
     * negocio está en `catalog.business` y la tasa viaja sola en `currency`.
     */
    public function test_the_page_carries_no_duplicated_business_nor_rate(): void
    {
        ExchangeRate::factory()->create(['rate' => '42.50000000']);
        $this->stock([$this->product('Taladro')]);

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('business')
            ->missing('rate')
            ->missing('product.business')
            ->where('currency.rate.rate', 42.5));
    }

    // ------------------------------------------------------------------
    // Cuentas de cobro (RB-5, SP-6)
    // ------------------------------------------------------------------

    public function test_only_the_active_payment_accounts_travel_in_their_order(): void
    {
        $this->stock([$this->product('Taladro')]);
        $this->withinOrganization($this->organization, function (): void {
            PaymentAccount::factory()->create(['identifier' => '0414-2', 'position' => 2]);
            PaymentAccount::factory()->inactive()->create(['identifier' => '0414-inactiva', 'position' => 0]);
            PaymentAccount::factory()->create(['identifier' => '0414-1', 'position' => 1]);
        });
        $this->withinOrganization($this->neighbour, fn () => PaymentAccount::factory()->create(['identifier' => '0414-vecina']));

        $accounts = $this->props($this->visit('taladro'))['product']['paymentAccounts'];

        $this->assertSame(['0414-1', '0414-2'], array_column($accounts, 'identifier'));
        $this->assertSame([true, true], array_column($accounts, 'isActive'));
        $this->assertSame('Pago móvil', $accounts[0]['methodLabel']);
    }

    public function test_without_active_accounts_the_list_is_empty(): void
    {
        $this->stock([$this->product('Taladro')]);
        $this->withinOrganization($this->organization, fn () => PaymentAccount::factory()->inactive()->create());

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page->where('product.paymentAccounts', []));
    }

    // ------------------------------------------------------------------
    // Apartar (RB-7, RB-10, SP-7)
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>, bool, UnavailableReason|null, int}>
     */
    public static function reservability(): iterable
    {
        yield 'con unidades' => [['stock' => 3], true, null, 3];
        yield 'con muchas, topado a diez (RB-10)' => [['stock' => 50], true, null, ProductDetailResource::MAX_QUANTITY];
        yield 'agotado' => [['stock' => 0], false, UnavailableReason::OutOfStock, 0];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('reservability')]
    public function test_it_says_whether_it_can_be_reserved_and_why_not(array $attributes, bool $can, ?UnavailableReason $reason, int $max): void
    {
        $this->stock([$this->product('Taladro', $attributes)]);

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.canBeReserved', $can)
            ->where('product.unavailableReason', $reason?->message())
            ->where('product.maxQuantity', $max));
    }

    /**
     * SP-7 · unas condiciones que no dan un plan —colado por otra puerta que
     * el CRUD— se explican, no rompen la página.
     */
    public function test_incoherent_terms_are_not_reservable_and_do_not_break_the_page(): void
    {
        $this->stock([$this->product('Taladro')]);
        DB::table('products')->where('slug', 'taladro')->update(['down_payment_cents' => 25_000]);

        $this->visit('taladro')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.canBeReserved', false)
            ->where('product.unavailableReason', UnavailableReason::NotReservable->message())
            ->where('product.installmentAmount', null));
    }

    /**
     * RB-7, `REQ-SUB-004` RB-4 (`T-067`) · con el cupo de apartados activos
     * agotado, la ficha **no ofrece apartar** y lo dice con el mensaje neutro:
     * ni el plan, ni el límite, ni que el negocio tenga que pagar. La vecina,
     * con su cupo libre, sigue ofreciéndolo.
     */
    public function test_a_business_at_its_layaway_limit_shows_the_neutral_reason(): void
    {
        $this->stock([$this->product('Taladro')]);
        $this->withinOrganization($this->organization, function (): void {
            Subscription::query()->firstOrFail()->plan->forceFill(['max_active_layaways' => 2])->save();
            UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => 2, 'computed_at' => now()]);
        });

        $page = $this->visit('taladro')->assertOk();

        $page->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.canBeReserved', false)
            ->where('product.unavailableReason', UnavailableReason::BusinessUnavailable->message()));

        $props = mb_strtolower((string) json_encode($page->viewData('page')['props']['product'], JSON_UNESCAPED_UNICODE));
        foreach (['plan', 'límite', 'limite', 'suscrip', 'free'] as $word) {
            $this->assertStringNotContainsString($word, $props, "La ficha revela «{$word}» al cliente final.");
        }

        // `REQ-SUB-004` SP-2 · y queda la evidencia de la venta perdida —una
        // por hora, no una por visita—, que es la que enciende el aviso del
        // panel ahora que el formulario ya no se ofrece.
        $this->visit('taladro')->assertOk();
        $this->assertSame(
            ['active_layaways'],
            $this->withinOrganization($this->organization, fn (): array => PlanLimitRejection::query()->get()->map(fn (PlanLimitRejection $row): string => $row->metric->value)->all()),
        );
        $this->assertSame(0, $this->withinOrganization($this->neighbour, fn (): int => PlanLimitRejection::query()->count()));
    }

    /**
     * Con la suscripción suspendida —aunque la organización siga activa—
     * tampoco se ofrece apartar; y eso **no** es una venta perdida por el
     * límite: subir de plan no lo arregla.
     */
    public function test_a_suspended_subscription_shows_the_neutral_reason_without_a_lost_sale(): void
    {
        $this->stock([$this->product('Taladro')]);
        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['status' => SubscriptionStatus::Suspended->value]));

        $this->visit('taladro')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.canBeReserved', false)
            ->where('product.unavailableReason', UnavailableReason::BusinessUnavailable->message()));

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => PlanLimitRejection::query()->count()));
    }

    /**
     * Y una organización suspendida tampoco ofrece apartar, con el mismo motivo.
     */
    public function test_a_suspended_business_shows_the_neutral_reason(): void
    {
        $this->stock([$this->product('Taladro')]);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        // La suspensión despublica (`T-071`); aquí se mira solo la ficha.

        $this->visit('taladro')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.canBeReserved', false)
            ->where('product.unavailableReason', UnavailableReason::BusinessUnavailable->message()));
    }

    // ------------------------------------------------------------------
    // Fotos, ficha técnica y relacionados (RB-9, SP-4, SP-5)
    // ------------------------------------------------------------------

    public function test_without_images_or_specifications_both_lists_are_empty(): void
    {
        $this->stock([$this->product('Taladro')]);

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.images', [])
            ->where('product.specificationGroups', []));
    }

    public function test_the_specifications_travel_grouped(): void
    {
        $product = $this->product('Taladro');
        $this->stock([$product]);
        $this->withinOrganization($this->organization, function () use ($product): void {
            ProductSpecification::factory()->forProduct($product)->create(['label' => 'Potencia', 'value' => '800 W', 'position' => 1]);
            ProductSpecification::factory()->forProduct($product)->inGroup('Motor')->create(['label' => 'Velocidad', 'value' => '3000 rpm', 'position' => 2]);
        });

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.specificationGroups', [
                ['group' => null, 'items' => [['label' => 'Potencia', 'value' => '800 W']]],
                ['group' => 'Motor', 'items' => [['label' => 'Velocidad', 'value' => '3000 rpm']]],
            ]));
    }

    /**
     * RB-9 · hasta cuatro, del **mismo catálogo**, sin el actual: los de su
     * categoría primero y, para completar, los demás en el orden curado.
     */
    public function test_related_products_are_from_this_catalog_same_category_first(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            $tools = Category::factory()->named('Herramientas')->create();
            $paint = Category::factory()->named('Pinturas')->create();

            $this->attach($this->catalog, [
                $this->product('Pintura A', ['category_id' => $paint->getKey()]),
                $this->product('Taladro', ['category_id' => $tools->getKey()]),
                $this->product('Pintura B', ['category_id' => $paint->getKey()]),
                $this->product('Sierra', ['category_id' => $tools->getKey()]),
                Product::factory()->create(['name' => 'Borrador', 'slug' => 'borrador', 'category_id' => $tools->getKey()]),
                $this->product('Pintura C', ['category_id' => $paint->getKey()]),
                $this->product('Broca', ['category_id' => $tools->getKey()]),
            ]);
            $this->attach(Catalog::factory()->published()->create(), [$this->product('Lijadora ajena', ['category_id' => $tools->getKey()])]);
        });

        $related = array_column($this->props($this->visit('taladro'))['related'], 'slug');

        $this->assertSame(['sierra', 'broca', 'pintura-a', 'pintura-b'], $related);
    }

    public function test_a_product_alone_in_its_catalog_has_no_related(): void
    {
        $this->stock([$this->product('Taladro')]);

        $this->visit('taladro')->assertInertia(fn (AssertableInertia $page) => $page->where('related', []));
    }

    /**
     * Sin `count(*)`, como todo lo público, y sin una consulta por
     * relacionado.
     */
    public function test_the_page_does_not_count_nor_query_per_related_product(): void
    {
        $this->stock([$this->product('Taladro'), $this->product('Sierra')]);
        $this->visit('taladro'); // la tasa y los ajustes se quedan en caché

        $few = $this->queries('taladro');
        $this->stock([$this->product('Broca'), $this->product('Lija'), $this->product('Martillo')]);
        $many = $this->queries('taladro');

        $this->assertSame(count($few), count($many));
        $this->assertSame([], array_values(array_filter($many, fn (string $sql): bool => str_contains(strtolower($sql), 'count('))));
    }

    /**
     * RB-8 · `product_viewed` y la permanencia son de `T-084`. El día que
     * exista el bus, este caso se pone rojo para que la ficha los emita.
     */
    public function test_the_product_view_telemetry_is_still_pending(): void
    {
        $this->assertFileDoesNotExistAnywhere('TelemetryEventName.php',
            'El bus de telemetría ya existe (T-084): emite product_viewed desde la ficha, con catalog_id y '
            .'payload.source, arranca useDwellTime, y borra este caso.',
        );
    }

    // ------------------------------------------------------------------

    private function assertFileDoesNotExistAnywhere(string $file, string $message): void
    {
        $found = Finder::create()->files()->in([app_path(), resource_path('js')])->name($file);

        $this->assertSame(0, iterator_count($found), $message);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(string $name, array $attributes = []): Product
    {
        $create = fn (): Product => Product::factory()->published()->create(['name' => $name, 'slug' => Str::slug($name), ...$attributes]);

        // Dentro del tenant que esté fijado, o del negocio del test si no hay.
        return app(TenantContext::class)->hasOrganization()
            ? $create()
            : $this->withinOrganization($this->organization, $create);
    }

    /**
     * @param  list<Product>  $products
     */
    private function stock(array $products): void
    {
        $this->withinOrganization($this->organization, fn () => $this->attach($this->catalog, $products));
    }

    /**
     * @param  list<Product>  $products  en el orden curado, detrás de los que ya hay
     */
    private function attach(Catalog $catalog, array $products): void
    {
        $offset = (int) DB::table('catalog_product')->where('catalog_id', $catalog->getKey())->max('position');

        foreach ($products as $index => $product) {
            $catalog->products()->attach($product, ['position' => $offset + $index + 1]);
        }
    }

    private function subscribe(Organization $organization): void
    {
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan(Plan::factory()->create())->create());
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visit(string $product): TestResponse
    {
        $this->withoutOrganization();

        return $this->get('/c/'.$this->catalog->slug.'/p/'.$product);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visitCatalog(): TestResponse
    {
        $this->withoutOrganization();

        return $this->get('/c/'.$this->catalog->slug);
    }

    /**
     * @return list<string>
     */
    private function queries(string $product): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit($product)->assertOk();
        $queries = array_map(fn (array $query): string => (string) $query['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
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
}
