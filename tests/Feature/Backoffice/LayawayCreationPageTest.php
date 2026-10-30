<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LayawayCreationPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const NOTE = 'Efectivo recibido en tienda por Ana al apartar.';

    private Organization $organization;

    private User $member;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'America/Caracas'));
        Notification::fake();
        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);

        [$this->member, $this->product, $this->customer] = $this->withinOrganization($this->organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create(['max_active_layaways' => 5]))->create();
            ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);

            return [
                User::factory()->create(['name' => 'Ana Pérez']),
                Product::factory()->published()->create([
                    'name' => 'Nevera Mabe 14 pies',
                    'sku' => 'NEV-14',
                    'price_cents' => 20_000,
                    'down_payment_cents' => 5_000,
                    'installments_count' => 5,
                    'frequency_days' => 15,
                    'stock' => 3,
                ]),
                Customer::factory()->create(['full_name' => 'María González', 'phone' => '+584141234567', 'email' => 'maria@ejemplo.test']),
            ];
        });

        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_offers_only_the_reservable_products_of_the_business(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            Product::factory()->create(['status' => ProductStatus::Draft, 'name' => 'Borrador']);
            Product::factory()->published()->create(['stock' => 0, 'name' => 'Agotado']);
        });
        $this->withinOrganization($this->neighbourOrganization(), fn () => Product::factory()->published()->create(['stock' => 5, 'name' => 'Del vecino']));
        $this->withoutOrganization();

        $this->actingAs($this->member)
            ->get(route('layaways.create', ['cliente' => $this->customer->getKey()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/layaways/Create')
                ->has('products', 1)
                ->where('products.0.name', 'Nevera Mabe 14 pies')
                ->where('products.0.maxQuantity', 3)
                ->where('plan', null)
                ->where('customer.id', $this->customer->getKey())
                ->where('customer.name', 'María González')
                ->where('window.defaultHours', 72)
                ->where('payment.paidAt.min', '2026-09-10')
                ->where('payment.paidAt.max', '2026-09-10')
                ->where('urls.store', route('layaways.store')));
    }

    public function test_the_plan_follows_the_chosen_product_and_quantity(): void
    {
        $this->actingAs($this->member)
            ->get(route('layaways.create', ['producto' => $this->product->getKey(), 'cantidad' => 2]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selection.product', $this->product->getKey())
                ->where('selection.quantity', 2)
                ->where('plan.productName', 'Nevera Mabe 14 pies')
                ->where('plan.total.cents', 40_000)
                ->where('plan.downPayment.cents', 10_000)
                ->where('plan.installmentsCount', 5)
                ->where('plan.frequencyDays', 15));

        $this->get(route('layaways.create', ['producto' => $this->product->getKey(), 'cantidad' => 9]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selection.quantity', 3));
    }

    public function test_for_an_existing_customer_it_lands_on_the_new_layaway(): void
    {
        $response = $this->actingAs($this->member)->post(route('layaways.store'), $this->payload());

        $layaway = $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->sole());
        $response->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHas('status', sprintf('Apartado %s creado para María González.', $layaway->code));
        $this->assertSame([(int) $this->customer->getKey(), (int) $this->member->getKey()], [$layaway->customer_id, $layaway->created_by_user_id]);

        $this->withoutOrganization();
        $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activity.0.kind', 'created')
            ->where('activity.0.detail', 'Creado por Ana Pérez desde el backoffice'));
    }

    public function test_a_new_customer_needs_the_declared_consent(): void
    {
        $this->actingAs($this->member)->from(route('layaways.create'));

        $this->post(route('layaways.store'), $this->newCustomer(['terms_accepted' => '0']))
            ->assertSessionHasErrors(['terms_accepted' => 'Marca que el cliente aceptó las condiciones del apartado.']);
        $this->assertSame(0, DB::table('layaways')->count());

        $this->post(route('layaways.store'), $this->newCustomer())->assertSessionHasNoErrors();

        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->where('document_number', 'V23456789')->sole());
        $this->assertSame([(int) $this->member->getKey(), '127.0.0.1'], [$customer->terms_declared_by_user_id, $customer->terms_ip_address]);
    }

    public function test_a_payment_at_creation_activates_it_and_is_summarised(): void
    {
        $this->actingAs($this->member)->post(route('layaways.store'), $this->payload($this->payment('80.00')))
            ->assertSessionHas('status', fn (string $status): bool => str_ends_with($status, 'creado para María González. Pago registrado: $80,00 (inicial de $50,00; $30,00 a la cuota 1).'));

        $layaway = $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->sole());
        $this->assertSame(LayawayStatus::Active, $layaway->status);
    }

    public function test_a_payment_below_the_down_payment_is_credited_and_the_layaway_waits_for_the_rest(): void
    {
        $this->actingAs($this->member)->from(route('layaways.create'))
            ->post(route('layaways.store'), $this->payload($this->payment('20.00')))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status): bool => str_ends_with($status, 'Pago registrado: $20,00 (abono a la inicial de $20,00, faltan $30,00).'));

        $layaway = $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->sole());
        $this->assertSame(LayawayStatus::PendingDownPayment, $layaway->status);
        $this->assertSame([2_000, 1], [$layaway->paid_cents->cents, DB::table('payments')->count()]);

        $this->get(route('layaways.show', $layaway))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaway.downPaymentProgress.paid.usd', '$20,00')
            ->where('layaway.downPaymentProgress.missing.usd', '$30,00'));
    }

    public function test_the_window_can_be_custom_or_without_expiry(): void
    {
        $this->actingAs($this->member);

        $this->post(route('layaways.store'), $this->payload(['window' => 'days', 'window_value' => '2']))->assertSessionHasNoErrors();
        $this->post(route('layaways.store'), $this->payload(['window' => 'none']))->assertSessionHasNoErrors();
        $this->post(route('layaways.store'), $this->payload(['window' => 'hours', 'window_value' => '0']))->assertSessionHasErrors('window_value');

        $expiries = DB::table('layaways')->orderBy('id')->pluck('expires_at')->all();
        $this->assertSame(['2026-09-12 12:00:00', null], $expiries);
    }

    public function test_each_refusal_comes_back_in_its_field(): void
    {
        $foreignProduct = $this->withinOrganization($this->neighbourOrganization(), fn (): Product => Product::factory()->published()->create(['stock' => 5]));
        $foreignCustomer = $this->withinOrganization($this->neighbourOrganization(), fn (): Customer => Customer::factory()->create());
        $draft = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create(['status' => ProductStatus::Draft, 'name' => 'Borrador']));
        $this->withoutOrganization();
        $this->actingAs($this->member)->from(route('layaways.create'));

        $this->post(route('layaways.store'), $this->payload(['quantity' => '4']))->assertSessionHasErrors('quantity');
        $this->post(route('layaways.store'), $this->payload(['product' => (string) $draft->getKey()]))
            ->assertSessionHasErrors(['product' => '«Borrador» no está publicado: publícalo para apartarlo.']);
        $this->post(route('layaways.store'), $this->payload(['product' => (string) $foreignProduct->getKey()]))
            ->assertSessionHasErrors(['product' => 'Ese producto no es de tu negocio.']);
        $this->post(route('layaways.store'), $this->payload(['customer' => (string) $foreignCustomer->getKey()]))
            ->assertSessionHasErrors(['customer' => 'Esa ficha de cliente no es de tu negocio.']);
        $this->post(route('layaways.store'), $this->payload(['down_payment' => '0.00']))->assertSessionHasNoErrors();

        $this->assertSame(5_000, (int) DB::table('layaways')->value('down_payment_cents'));
    }

    public function test_the_plan_limit_is_shown_as_it_is(): void
    {
        $this->withinOrganization($this->organization, fn () => UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => 5, 'computed_at' => now()]));
        $this->withoutOrganization();

        $this->actingAs($this->member)->from(route('layaways.create'))
            ->post(route('layaways.store'), $this->payload())
            ->assertSessionHasErrors('plan');

        $this->assertSame(0, DB::table('layaways')->count());
    }

    public function test_a_suspended_business_cannot_open_nor_send_the_form(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();
        $this->actingAs($this->member);

        $this->get(route('layaways.create'))->assertForbidden();
        $this->post(route('layaways.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, DB::table('layaways')->count());
    }

    public function test_the_same_key_twice_lands_on_the_same_layaway(): void
    {
        $payload = $this->payload($this->payment('50.00'));
        $this->actingAs($this->member);

        $first = $this->post(route('layaways.store'), $payload);
        $this->withoutOrganization();
        $second = $this->post(route('layaways.store'), $payload);

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame([1, 1], [DB::table('layaways')->count(), DB::table('payments')->count()]);
        $this->assertSame(1, $this->withinOrganization($this->organization, fn (): int => AuditEntry::query()->ofEvent(AuditEvent::LayawayCreated)->count()));
    }

    public function test_the_customer_search_stays_inside_the_business(): void
    {
        $this->withinOrganization($this->neighbourOrganization(), fn () => Customer::factory()->create(['full_name' => 'María Vecina']));
        $this->withoutOrganization();

        $this->actingAs($this->member)->getJson(route('layaways.create.customers', ['q' => 'María']))
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'María González');
    }

    public function test_the_payment_preview_follows_the_chosen_terms(): void
    {
        $this->actingAs($this->member);
        $query = ['product' => $this->product->getKey(), 'quantity' => 2, 'currency' => 'USD'];

        $this->getJson(route('layaways.create.payment-preview', [...$query, 'amount' => '130.00']))
            ->assertOk()
            ->assertJsonPath('preview.summary', 'Activará el apartado con la inicial de $100,00; se aplicará a la cuota 1.')
            ->assertJsonPath('preview.allocations.0.covers', false);
        $this->getJson(route('layaways.create.payment-preview', [...$query, 'amount' => '99.99']))
            ->assertOk()
            ->assertJsonPath('preview.summary', 'Abonará $99,99 a la inicial y faltarán $0,01 para activar el apartado.')
            ->assertJsonPath('preview.downPaymentMissing', '$0,01')
            ->assertJsonPath('preview.statusAfter', 'pending_down_payment');
        $this->getJson(route('layaways.create.payment-preview', [...$query, 'amount' => '500.00']))
            ->assertOk()->assertJsonPath('preview.unallocatedCents', 10_000)->assertJsonPath('preview.statusAfter', 'completed');
        $this->getJson(route('layaways.create.payment-preview', ['amount' => '10.00', 'currency' => 'USD']))->assertStatus(422);

        $this->assertSame([0, 0], [DB::table('layaways')->count(), DB::table('payments')->count()]);
    }

    public function test_the_word_nuevo_is_not_read_as_a_layaway_code(): void
    {
        $this->actingAs($this->member)->get('/backoffice/apartados/nuevo')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/layaways/Create'));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'product' => (string) $this->product->getKey(),
            'quantity' => '1',
            'customer_mode' => 'existing',
            'customer' => (string) $this->customer->getKey(),
            'window' => 'default',
            'with_payment' => '0',
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function newCustomer(array $overrides = []): array
    {
        return $this->payload([
            'customer_mode' => 'new',
            'customer' => '',
            'full_name' => 'Pedro Rojas',
            'document_number' => 'V-23456789',
            'phone' => '0424-7654321',
            'email' => '',
            'terms_accepted' => '1',
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function payment(string $amount): array
    {
        return [
            'with_payment' => '1',
            'method' => 'efectivo_usd',
            'amount' => $amount,
            'currency' => 'USD',
            'paid_at' => '2026-09-10',
            'note' => self::NOTE,
        ];
    }
}
