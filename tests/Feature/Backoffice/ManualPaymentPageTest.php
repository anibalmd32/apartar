<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ManualPaymentPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const NOTE = 'Pago en efectivo recibido en tienda por Ana.';

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'America/Caracas'));
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'Asia/Tokyo']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_layaway_view_offers_the_form_while_it_collects(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->withinOrganization($this->organization, fn () => PaymentAccount::factory()->create(['is_active' => false]));
        $completed = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('manualPayment.storeUrl', route('layaways.payments.store', $layaway))
            ->where('manualPayment.paidAt.max', '2026-09-11')
            ->where('manualPayment.paymentAccounts.0.isActive', false)
            ->where('manualPayment.rate.rate', '42.50000000')
            ->has('payments', 0));

        $this->get(route('layaways.show', $completed))->assertInertia(fn (AssertableInertia $page) => $page->where('manualPayment', null));
    }

    public function test_recording_a_payment_returns_to_the_layaway(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member);

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['amount' => '80.00']))
            ->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHas('status', 'Pago registrado: $80,00 (inicial de $50,00; $30,00 a la cuota 1).');

        $this->withoutOrganization();
        $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.method', 'Efectivo en dólares')
            ->where('payments.0.registeredByBusiness', true)
            ->where('payments.0.note', self::NOTE));
    }

    public function test_a_payment_below_the_down_payment_is_credited_and_the_page_shows_what_is_missing(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member);

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['amount' => '40.00']))
            ->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHas('status', 'Pago registrado: $40,00 (abono a la inicial de $40,00, faltan $10,00).');

        $this->withoutOrganization();
        $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaway.status', 'pending_down_payment')
            ->where('layaway.paid.usd', '$40,00')
            ->where('layaway.downPaymentProgress.paid.usd', '$40,00')
            ->where('layaway.downPaymentProgress.missing.usd', '$10,00'));

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['amount' => '40.00']))
            ->assertSessionHas('status', 'Pago registrado: $40,00 (abono de $10,00 que completa la inicial; $30,00 a la cuota 1).');
        $this->assertSame('active', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
    }

    public function test_each_refusal_comes_back_in_its_field(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): PaymentAccount => PaymentAccount::factory()->create());
        $this->withoutOrganization();
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('layaways.payments.store', $layaway), $this->payload(['note' => 'Efectivo']))->assertSessionHasErrors('note');
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['amount' => '0.00']))->assertSessionHasErrors('amount');
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['paid_at' => '2026-09-12']))
            ->assertSessionHasErrors(['paid_at' => 'La fecha del pago no puede ser futura.']);
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['paid_at' => '2026-09-09']))
            ->assertSessionHasErrors(['paid_at' => 'La fecha del pago no puede ser anterior a la creación del apartado.']);
        $this->post(route('layaways.payments.store', $layaway), $this->payload(['payment_account' => (string) $foreign->getKey()]))
            ->assertSessionHasErrors(['payment_account' => 'Esa cuenta de cobro no es de tu negocio.']);

        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_an_excess_comes_back_to_its_confirmation_until_confirmed(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $payload = $this->payload(['amount' => '250.00']);
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('layaways.payments.store', $layaway), $payload)->assertSessionHasErrors('confirm_excess');
        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'confirm_excess' => '6000'])->assertSessionHasErrors('confirm_excess');
        $this->assertSame(0, DB::table('payments')->count());

        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'confirm_excess' => '5000'])->assertRedirect(route('layaways.show', $layaway));
        $this->assertSame(5_000, (int) DB::table('payments')->value('unallocated_cents'));
    }

    public function test_bolivars_with_a_hand_written_rate_to_an_own_account(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->member)->post(route('layaways.payments.store', $layaway), $this->payload([
            'method' => 'pago_movil',
            'bank' => 'Banesco',
            'reference' => '0123456789',
            'payment_account' => (string) $account->getKey(),
            'amount' => '2000.00',
            'currency' => 'VES',
            'rate' => '40.00',
        ]))->assertRedirect(route('layaways.show', $layaway));

        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->sole());
        $this->assertSame(
            ['pago_movil', $account->getKey(), '2026-09-11', 5_000, 'manual'],
            [$payment->payment_method?->value, $payment->payment_account_id, $payment->paid_on?->toDateString(), $payment->amount_cents_usd->cents, $payment->rate_source->value],
        );
    }

    public function test_a_key_that_recorded_another_payment_says_so(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $payload = $this->payload(['amount' => '50.00']);
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('layaways.payments.store', $layaway), $payload)->assertRedirect(route('layaways.show', $layaway));
        $this->withoutOrganization();
        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'amount' => '30.00'])
            ->assertSessionHasErrors(['amount' => 'Este formulario ya registró otro pago. Recarga la página para registrar uno nuevo.']);

        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_a_double_submit_records_one_payment(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $payload = $this->payload(['amount' => '50.00']);
        $this->actingAs($this->member);

        $this->post(route('layaways.payments.store', $layaway), $payload)->assertRedirect(route('layaways.show', $layaway));
        $this->withoutOrganization();
        $this->post(route('layaways.payments.store', $layaway), $payload)->assertRedirect(route('layaways.show', $layaway));

        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_the_preview_says_what_recording_would_do(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member);

        $this->getJson(route('layaways.payments.preview', [$layaway, 'amount' => '80.00', 'currency' => 'USD']))
            ->assertOk()->assertJsonPath('preview.summary', 'Activará el apartado con la inicial de $50,00; se aplicará a la cuota 1.');
        $this->getJson(route('layaways.payments.preview', [$layaway, 'amount' => '250.00', 'currency' => 'USD']))
            ->assertOk()->assertJsonPath('preview.unallocatedCents', 5_000);
        $this->getJson(route('layaways.payments.preview', [$layaway, 'amount' => '40.00', 'currency' => 'USD']))
            ->assertOk()
            ->assertJsonPath('preview.summary', 'Abonará $40,00 a la inicial y faltarán $10,00 para activar el apartado.')
            ->assertJsonPath('preview.statusAfter', 'pending_down_payment');
        $this->getJson(route('layaways.payments.preview', $layaway).'?amount[]=1&currency=USD')->assertStatus(422);
        $this->getJson(route('layaways.payments.preview', [$layaway, 'amount' => '0.00', 'currency' => 'USD']))->assertStatus(422);

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame('pending_down_payment', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
    }

    public function test_it_works_with_the_organization_suspended(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->member)->post(route('layaways.payments.store', $layaway), $this->payload(['amount' => '50.00']))
            ->assertRedirect(route('layaways.show', $layaway));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'method' => 'efectivo_usd',
            'amount' => '30.00',
            'currency' => 'USD',
            'paid_at' => '2026-09-11',
            'note' => self::NOTE,
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }
}
