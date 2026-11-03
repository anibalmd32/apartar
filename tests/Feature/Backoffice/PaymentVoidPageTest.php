<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentVoidPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const REASON = 'El comprobante era de otro cliente.';

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_el_detalle_ofrece_anular_y_muestra_el_pago_anulado_tachado(): void
    {
        [$layaway, $payment] = $this->paidLayaway();

        $this->actingAs($this->member)->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.voidUrl', route('payments.void', $payment))
            ->where('payments.0.voided', null)
            ->where('payments.0.fromReceipt', false));

        $this->post(route('payments.void', $payment), ['reason' => self::REASON])
            ->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHas('status', 'Pago de $60,00 anulado.');
        $this->withoutOrganization();

        $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 2)
            ->where('layaway.paid.cents', 5_000)
            ->where('payments.0.voidUrl', null)
            ->where('payments.0.voided', ['at' => '10 sept 2026', 'by' => 'Ana Pérez', 'reason' => self::REASON])
            ->where('payments.0.appliedTo', [])
            ->where('payments.0.downPayment', null)
            ->where('reallocation', null));

        $this->from(route('layaways.show', $layaway))->post(route('payments.void', $payment), ['reason' => self::REASON])
            ->assertSessionHasErrors(['payment' => 'Este pago ya está anulado.']);
    }

    public function test_el_portal_no_muestra_los_pagos_anulados(): void
    {
        [$layaway, $payment] = $this->paidLayaway();
        $this->actingAs($this->member)->post(route('payments.void', $payment), ['reason' => self::REASON]);
        $this->withoutOrganization();
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
        $this->withoutOrganization();

        $this->actingAs($customer, 'customer')
            ->get(route('portal.layaways.show', ['code' => $layaway->code]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments', 1)
                ->where('payments.0.amountUsd.cents', 5_000)
                ->where('payments.0.voidUrl', null));
    }

    public function test_cada_rechazo_vuelve_con_su_mensaje(): void
    {
        [$layaway, $payment] = $this->paidLayaway();
        $downPayment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->where('layaway_id', $layaway->getKey())->oldest('id')->firstOrFail());
        $this->withoutOrganization();
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->post(route('payments.void', $payment), ['reason' => 'Corto'])
            ->assertSessionHasErrors(['reason' => 'El motivo tiene que tener al menos 10 caracteres.']);
        $this->post(route('payments.void', $payment), [])
            ->assertSessionHasErrors(['reason' => 'Escribe el motivo de la anulación.']);
        $this->withoutOrganization();
        $this->post(route('payments.void', $downPayment), ['reason' => self::REASON])
            ->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHasErrors(['payment' => 'Este pago cubrió la inicial: para anularlo, registra antes el pago correcto de la inicial o cancela el apartado.']);

        $this->assertSame(0, DB::table('payments')->where('layaway_id', $layaway->getKey())->whereNotNull('voided_at')->count());
    }

    public function test_un_apartado_cancelado_ofrece_anular_y_mover_pero_no_editar_y_un_extrano_no_puede(): void
    {
        [$layaway, $payment] = $this->paidLayaway();
        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Cancelled->value]);

        $this->actingAs($this->member)->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.voidUrl', route('payments.void', $payment))
            ->where('payments.0.correction.targetUrl', route('payments.target', $payment))
            ->where('correctionFields', null));
        $this->withoutOrganization();

        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->create());
        $this->withoutOrganization();
        $this->actingAs($stranger)->post(route('payments.void', $payment), ['reason' => self::REASON])->assertNotFound();
    }

    /**
     * @return array{Layaway, Payment}
     */
    private function paidLayaway(): array
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member);

        foreach (['50.00', '60.00'] as $amount) {
            $this->post(route('layaways.payments.store', $layaway), [
                'method' => 'efectivo_usd', 'amount' => $amount, 'currency' => 'USD', 'paid_at' => '2026-09-10',
                'note' => 'Pago en efectivo recibido en tienda por Ana.', 'idempotency_key' => (string) Str::uuid(),
            ])->assertRedirect(route('layaways.show', $layaway));
            $this->withoutOrganization();
        }

        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->where('layaway_id', $layaway->getKey())->latest('id')->firstOrFail());
        $this->withoutOrganization();

        return [$layaway, $payment];
    }
}
