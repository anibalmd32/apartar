<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\UsageMetric;
use App\Http\Controllers\Marketing\LegalController;
use App\Models\Catalog;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Notifications\LayawayConfirmationNotification;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El formulario de apartado y su confirmación, por HTTP (`REQ-LAY-001`
 * superficie, `REQ-IDN-005`, `T-045b`).
 *
 * La transacción está probada en `CreateLayawayActionTest`; aquí se prueba lo
 * que la rodea: qué ve el cliente antes de apartar —el plan del apartado
 * entero, calculado en el servidor—, que **cada rechazo vuelve a su campo**, que
 * el doble envío no crea ni escribe dos veces, y que la página de gracias es
 * solo de quien apartó.
 */
final class LayawayPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Catalog $catalog;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-27 10:00:00');
        Notification::fake();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        [$this->catalog, $this->product] = $this->withinOrganization($this->organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create(['max_active_layaways' => 5]))->create();
            PaymentAccount::factory()->create(['identifier' => '0414-7654321']);

            $catalog = Catalog::factory()->published()->create();
            // $150 de precio y $100 de inicial: $50 financiados por unidad en 3
            // cuotas, el ejemplo de `T-041` donde el reparto no es lineal.
            $product = Product::factory()->published()->create([
                'name' => 'Nevera Mabe',
                'slug' => 'nevera-mabe',
                'price_cents' => 15_000,
                'down_payment_cents' => 10_000,
                'installments_count' => 3,
                'frequency_days' => 15,
                'stock' => 3,
            ]);
            $catalog->products()->attach($product, ['position' => 1]);

            return [$catalog, $product];
        });

        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // El formulario
    // -------------------------------------------------------------------

    public function test_the_form_arrives_with_the_product_quantity_one_and_its_plan(): void
    {
        $this->get($this->formUrl())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catalog/LayawayCreate')
            ->where('product.slug', 'nevera-mabe')
            ->where('product.maxQuantity', 3)
            ->where('quantity', 1)
            ->where('plan.quantity', 1)
            ->where('plan.total.usd', '$150.00')
            ->where('plan.downPayment.usd', '$100.00')
            ->where('plan.installmentAmount.usd', '$16.66')
            ->where('plan.lastInstallmentAmount.usd', '$16.68')
            ->has('plan.installments', 3)
            ->where('consent.businessName', $this->organization->name)
            ->where('consent.platformTermsDrafted', LegalController::platformTermsAreDrafted())
            ->where('recognisedAreaCodes', fn (\Illuminate\Support\Collection $codes): bool => $codes->contains('414') && $codes->contains('422'))
            ->has('product.paymentAccounts', 1));
    }

    /**
     * §5 · el plan de **dos** unidades no es el de una multiplicado: $100
     * financiados en 3 son `33,33 · 33,33 · 33,34`, no `33,32 · 33,32 · 33,36`.
     * Llega por la recarga parcial de `plan`, que es lo que pide el formulario
     * al cambiar la cantidad.
     */
    public function test_the_plan_for_two_units_is_split_over_the_whole_layaway(): void
    {
        $props = $this->get($this->formUrl().'?quantity=2', $this->partial('plan'))->assertOk()->json('props');

        $this->assertArrayNotHasKey('product', $props, 'La recarga parcial trajo más de lo que pidió.');
        $this->assertSame(2, $props['plan']['quantity']);
        $this->assertSame('$300.00', $props['plan']['total']['usd']);
        $this->assertSame('$200.00', $props['plan']['downPayment']['usd']);
        $this->assertSame('$100.00', $props['plan']['financedAmount']['usd']);
        $this->assertSame('$33.33', $props['plan']['installmentAmount']['usd']);
        $this->assertSame('$33.34', $props['plan']['lastInstallmentAmount']['usd']);
        $this->assertSame([3_333, 3_333, 3_334], array_column(array_column($props['plan']['installments'], 'amount'), 'cents'));
        $this->assertSame('12 de octubre de 2026', $props['plan']['installments'][0]['estimatedDueDate']);
    }

    /** RB-5 · el resumen no sale de 1…min(stock, 10). */
    public function test_the_quantity_of_the_plan_is_bounded_by_the_stock(): void
    {
        $this->get($this->formUrl().'?quantity=99')->assertInertia(fn (AssertableInertia $page) => $page->where('quantity', 3)->where('plan.quantity', 3));
        $this->get($this->formUrl().'?quantity=0')->assertInertia(fn (AssertableInertia $page) => $page->where('quantity', 1));
    }

    /**
     * El formulario se abre como la ficha: un catálogo cerrado o un producto
     * que no está en él no tienen formulario.
     */
    public function test_the_form_of_a_closed_catalog_or_a_foreign_product_does_not_open(): void
    {
        $this->get("/c/{$this->catalog->slug}/apartar/no-existe")->assertNotFound();

        DB::table('catalogs')->where('id', $this->catalog->getKey())->update(['published_at' => null]);
        $this->get($this->formUrl())->assertNotFound();
    }

    // -------------------------------------------------------------------
    // El envío
    // -------------------------------------------------------------------

    public function test_submitting_creates_the_layaway_and_leads_to_the_thanks_page(): void
    {
        $response = $this->submit();

        $layaway = $this->onlyLayaway();
        $response->assertRedirect("/c/{$this->catalog->slug}/apartar/{$layaway->code}/gracias");

        // `REQ-IDN-005` RB-2 · la evidencia, con la IP de la petición.
        $customer = DB::table('customers')->where('id', $layaway->customer_id)->first();
        $this->assertNotNull($customer);
        $this->assertSame('127.0.0.1', $customer->terms_ip_address);
        $this->assertSame('2026-09-27 10:00:00', $customer->terms_accepted_at);

        Notification::assertSentOnDemand(
            LayawayConfirmationNotification::class,
            fn (LayawayConfirmationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->code === $layaway->code
                && $notifiable->routes['mail'] === 'maria@ejemplo.test',
        );
    }

    /**
     * RB-12 · el doble clic: la misma clave devuelve **el mismo** apartado, y el
     * correo sale una sola vez.
     */
    public function test_a_double_submission_returns_the_same_code_and_sends_one_email(): void
    {
        $key = (string) Str::uuid();

        $first = $this->submit(['idempotencyKey' => $key]);
        $second = $this->submit(['idempotencyKey' => $key]);

        $layaway = $this->onlyLayaway();
        $first->assertRedirect("/c/{$this->catalog->slug}/apartar/{$layaway->code}/gracias");
        $second->assertRedirect("/c/{$this->catalog->slug}/apartar/{$layaway->code}/gracias");

        Notification::assertSentOnDemandTimes(LayawayConfirmationNotification::class, 1);
    }

    /** RB-10 · sin correo no se manda nada, y la pantalla lo dice. */
    public function test_without_an_email_nothing_is_sent_and_the_thanks_page_says_so(): void
    {
        $this->submit(['email' => ''])->assertRedirect();

        Notification::assertNothingSent();

        $layaway = $this->onlyLayaway();
        $this->get($this->thanksUrl($layaway->code))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaway.hasEmail', false));
    }

    /** `T-040` · un prefijo fuera del censo no bloquea el apartado. */
    public function test_an_unrecognised_prefix_does_not_block_the_layaway(): void
    {
        $this->submit(['phone' => '0413-1234567'])->assertSessionHasNoErrors();

        $this->assertSame('+584131234567', DB::table('customers')->value('phone'));
    }

    // -------------------------------------------------------------------
    // Cada rechazo, a su campo
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFields(): array
    {
        return [
            'sin aceptar las condiciones' => [['termsAccepted' => false], 'termsAccepted'],
            'sin la casilla' => [['termsAccepted' => null], 'termsAccepted'],
            'sin nombre' => [['fullName' => '   '], 'fullName'],
            'documento sin forma' => [['documentNumber' => 'P-123'], 'documentNumber'],
            'sin teléfono' => [['phone' => ''], 'phone'],
            'teléfono de otro país' => [['phone' => '+1 305 555 0199'], 'phone'],
            'correo sin forma' => [['email' => 'maria.ejemplo.test'], 'email'],
            'más de diez' => [['quantity' => 11], 'quantity'],
            'ninguna' => [['quantity' => 0], 'quantity'],
            'sin clave' => [['idempotencyKey' => 'no-es-un-uuid'], 'idempotencyKey'],
        ];
    }

    /**
     * SP-6 · la forma de cada campo, validada en el servidor, con su error en
     * su campo. Inertia devuelve al formulario con lo escrito intacto.
     *
     * @param  array<string, mixed>  $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function test_each_invalid_field_comes_back_on_its_own_field(array $overrides, string $field): void
    {
        $this->from($this->formUrl())->submit($overrides)
            ->assertRedirect($this->formUrl())
            ->assertSessionHasErrors([$field]);

        $this->assertSame(0, DB::table('layaways')->count());
    }

    /**
     * SP-1 · stock insuficiente, con las que quedan, en `quantity`.
     */
    public function test_insufficient_stock_comes_back_on_the_quantity(): void
    {
        DB::table('products')->where('id', $this->product->getKey())->update(['stock' => 1]);

        $this->from($this->formUrl())->submit(['quantity' => 2])->assertSessionHasErrors(['quantity' => 'Solo queda 1 unidad disponible de este producto. Reduce la cantidad para apartar.']);
    }

    /**
     * SP-3 · el catálogo se cerró mientras el cliente escribía: un `422` que
     * conserva el formulario, **no** el `404` de la ficha.
     */
    public function test_a_catalog_closed_meanwhile_comes_back_as_a_form_error(): void
    {
        DB::table('catalogs')->where('id', $this->catalog->getKey())->update(['published_at' => null]);

        $this->from($this->formUrl())->submit()
            ->assertRedirect($this->formUrl())
            ->assertSessionHasErrors(['catalog' => 'Este catálogo ya no está disponible.']);
    }

    /** SP-4 · el producto se retiró mientras tanto, o nunca existió. */
    public function test_a_product_withdrawn_meanwhile_comes_back_as_a_form_error(): void
    {
        DB::table('products')->where('id', $this->product->getKey())->update(['status' => ProductStatus::Archived->value]);
        $this->from($this->formUrl())->submit()->assertSessionHasErrors(['product']);

        // Un producto que no existe —o de otra organización— no es un envío a
        // medias: es `404`, como la ficha.
        $this->post("/c/{$this->catalog->slug}/apartar/no-existe", $this->payload())->assertNotFound();
    }

    /** SP-2 · el cupo del plan, con el mensaje neutro. */
    public function test_an_exhausted_plan_comes_back_with_the_neutral_message(): void
    {
        $this->withinOrganization($this->organization, fn () => UsageCounter::query()->updateOrCreate(
            ['metric' => UsageMetric::ActiveLayaways],
            ['value' => 5, 'computed_at' => now()],
        ));
        $this->withoutOrganization();

        $this->from($this->formUrl())->submit()->assertSessionHasErrors(['catalog' => 'Este negocio no puede recibir nuevos apartados en este momento.']);
    }

    // -------------------------------------------------------------------
    // La página de gracias
    // -------------------------------------------------------------------

    /** RB-10 · el código, lo que se apartó, el plazo y dónde pagar. */
    public function test_the_thanks_page_shows_the_code_the_deadline_and_the_payment_accounts(): void
    {
        $this->submit(['quantity' => 2]);
        $layaway = $this->onlyLayaway();

        $this->get($this->thanksUrl($layaway->code))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catalog/LayawayThanks')
            ->where('layaway.code', $layaway->code)
            ->where('layaway.productName', 'Nevera Mabe')
            ->where('layaway.plan.total.usd', '$300.00')
            ->where('layaway.plan.downPayment.usd', '$200.00')
            ->where('layaway.expiresAt', '30 de septiembre a las 10:00 a. m.')
            ->where('layaway.hasEmail', true)
            ->has('paymentAccounts', 1)
            ->where('paymentAccounts.0.identifier', '0414-7654321')
            ->where('receiptUrl', route('portal.receipts.create', ['code' => $layaway->code])));
    }

    /**
     * Solo para la sesión que apartó: el código es media credencial del
     * portal, y la página de gracias no es una segunda puerta.
     */
    public function test_the_thanks_page_is_only_for_the_session_that_created_it(): void
    {
        $this->submit();
        $code = $this->onlyLayaway()->code;

        $this->flushSession();

        $this->get($this->thanksUrl($code))->assertNotFound();
    }

    /**
     * El envío es público y reserva stock 72 horas: se acota por IP
     * (`AppServiceProvider::LAYAWAY_SUBMISSIONS_PER_MINUTE`).
     */
    public function test_submissions_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < AppServiceProvider::LAYAWAY_SUBMISSIONS_PER_MINUTE; $i++) {
            $this->submit(['termsAccepted' => false])->assertStatus(302);
        }

        $this->submit(['termsAccepted' => false])->assertStatus(429);
    }

    // -------------------------------------------------------------------
    // Lo aplazado, en rojo el día que llegue
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------

    private function formUrl(): string
    {
        return "/c/{$this->catalog->slug}/apartar/nevera-mabe";
    }

    private function thanksUrl(string $code): string
    {
        return "/c/{$this->catalog->slug}/apartar/{$code}/gracias";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'quantity' => 1,
            'fullName' => 'María González',
            'documentNumber' => '12.345.678',
            'phone' => '0414-1234567',
            'email' => 'maria@ejemplo.test',
            'termsAccepted' => true,
            'idempotencyKey' => (string) Str::uuid(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function submit(array $overrides = []): TestResponse
    {
        $this->withoutOrganization();

        return $this->post($this->formUrl(), $this->payload($overrides));
    }

    /**
     * @return array<string, string>
     */
    private function partial(string $prop): array
    {
        $version = $this->get($this->formUrl())->viewData('page')['version'];

        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'catalog/LayawayCreate',
            'X-Inertia-Partial-Data' => $prop,
        ];
    }

    private function onlyLayaway(): Layaway
    {
        $this->assertSame(1, DB::table('layaways')->count());

        return $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->firstOrFail());
    }
}
