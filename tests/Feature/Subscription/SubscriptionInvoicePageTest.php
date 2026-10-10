<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Exchange\RateSource;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\ExchangeRate;
use App\Models\OrganizationSetting;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BillsSubscriptions;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * El historial de facturas y su pago desde el backoffice (`REQ-SUB-006`
 * RB-1, RB-4, RB-7, RB-10, `T-069`).
 */
final class SubscriptionInvoicePageTest extends TestCase
{
    use BillsSubscriptions, RefreshDatabase, UploadsReceiptFiles;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-03 12:00:00');
        Storage::fake(Receipt::DISK);
        $this->setUpBilling();
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Cada factura como `InvoiceRow`: el importe en dólares y en bolívares a
     * la tasa de la plataforma, `overdue` derivado del vencimiento en el día
     * del negocio, el motivo del último rechazo y si todavía se paga.
     */
    public function test_the_history_shows_each_invoice_as_an_invoice_row(): void
    {
        $overdue = $this->invoiceFor();
        $this->billed(fn () => SubscriptionReceipt::factory()->create([
            'subscription_invoice_id' => $overdue->getKey(),
            'submitted_by' => $this->billedOwner->getKey(),
            'status' => ReceiptStatus::Rejected,
            'rejection_reason' => 'Ilegible.',
        ]));
        $this->invoiceFor(['status' => InvoiceStatus::Paid, 'paid_cents' => 1_500, 'issued_at' => CarbonImmutable::now()->subMonth()]);

        $this->actingAs($this->billedOwner)
            ->get('/backoffice/suscripcion/facturas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/subscription/Invoices')
                ->has('invoices', 2)
                ->where('invoices.0.status', 'overdue')
                ->where('invoices.0.amount.usd', '$15.00')
                ->where('invoices.0.amount.ves', 'Bs. 637,50')
                ->where('invoices.0.dueDate', '2026-11-01')
                ->where('invoices.0.receiptStatus', 'rejected')
                ->where('invoices.0.rejectionReason', 'Ilegible.')
                ->where('invoices.0.payUrl', route('subscription.invoices.receipt', $overdue))
                ->where('invoices.1.status', 'paid')
                ->where('invoices.1.payUrl', null));
    }

    /** Pagar: el comprobante queda en revisión y se vuelve a la página con su aviso. */
    public function test_uploading_a_receipt_puts_the_invoice_under_review(): void
    {
        $invoice = $this->invoiceFor();

        $this->actingAs($this->billedOwner)
            ->post(route('subscription.invoices.receipt', $invoice), $this->payload())
            ->assertRedirect('/backoffice/suscripcion/facturas')
            ->assertSessionHas('status');

        $this->assertSame(InvoiceStatus::UnderReview, $invoice->fresh()?->status);
    }

    /** El duplicado vuelve en su campo, con el mensaje de [22]. */
    public function test_a_duplicate_comes_back_in_its_field(): void
    {
        $file = $this->receiptImage();
        $this->actingAs($this->billedOwner)->post(route('subscription.invoices.receipt', $this->invoiceFor()), $this->payload(['file' => $file]));

        $this->from('/backoffice/suscripcion/facturas')
            ->post(route('subscription.invoices.receipt', $this->invoiceFor()), $this->payload(['file' => $this->sameFileAs($file), 'reference' => '55555']))
            ->assertSessionHasErrors(['file' => 'Este comprobante ya fue reportado.']);
    }

    /** La factura de otra organización no existe aquí: `404`, y nada cambia. */
    public function test_another_business_invoice_is_not_found(): void
    {
        // El vecino **tiene** suscripción: el `404` tiene que venir de la factura, no de que falte la suya.
        $stranger = $this->withinOrganization($this->neighbourOrganization(), function (): User {
            Subscription::factory()->forPlan($this->paidPlan)->paid()->create();

            return User::factory()->owner()->create();
        });
        $invoice = $this->invoiceFor();

        $this->actingAs($stranger)->post(route('subscription.invoices.receipt', $invoice), $this->payload())->assertNotFound();

        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()?->status);
    }

    /** Quien no gobierna la facturación recibe `403`. */
    public function test_a_member_gets_403(): void
    {
        $invoice = $this->invoiceFor();
        $member = $this->billed(fn (): User => User::factory()->create(['role' => OrganizationRole::Member]));
        $this->actingAs($member)->get('/backoffice/suscripcion/facturas')->assertForbidden();
        $this->actingAs($member)->post(route('subscription.invoices.receipt', $invoice), $this->payload())->assertForbidden();
        // Sin datos: el `403` llega antes de validar, no un `422`.
        $this->post(route('subscription.invoices.receipt', $invoice), [])->assertForbidden();

        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()?->status);
    }

    /** Pagar es lo que levanta la suspensión: las dos rutas sobreviven a ella. */
    public function test_paying_survives_the_suspension(): void
    {
        $this->billedOrganization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $invoice = $this->invoiceFor();

        $this->actingAs($this->billedOwner)->get('/backoffice/suscripcion/facturas')->assertOk();
        $this->post(route('subscription.invoices.receipt', $invoice), $this->payload())->assertRedirect('/backoffice/suscripcion/facturas');
    }

    /**
     * Revisión de `T-069` · de una factura pagada no se comenta su último
     * comprobante (el rechazado por «ya pagada» invitaría a enviar otro) ni
     * se recalcula el bolívar a la tasa de hoy (RB-4: lo pagado está
     * congelado). Y el bolívar de una pendiente es el de la tasa **de la
     * plataforma**, no la del negocio.
     */
    public function test_a_closed_invoice_shows_no_receipt_and_no_bolivars_of_today(): void
    {
        ExchangeRate::factory()->create(['source' => RateSource::Parallel, 'rate' => '50.00000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->billed(fn () => OrganizationSetting::factory()->forKey(SettingKey::ExchangeRateSource, RateSource::Parallel->value)->create());
        $paid = $this->invoiceFor(['status' => InvoiceStatus::Paid, 'paid_cents' => 1_500]);
        $this->billed(fn () => SubscriptionReceipt::factory()->create([
            'subscription_invoice_id' => $paid->getKey(),
            'submitted_by' => $this->billedOwner->getKey(),
            'status' => ReceiptStatus::Rejected,
            'rejection_reason' => 'La factura ya está pagada con otro comprobante.',
        ]));
        $this->invoiceFor(['issued_at' => CarbonImmutable::now()->addMinute()]);

        $this->actingAs($this->billedOwner)
            ->get('/backoffice/suscripcion/facturas')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.0.amount.ves', 'Bs. 637,50')
                ->where('invoices.1.status', 'paid')
                ->where('invoices.1.receiptStatus', 'none')
                ->where('invoices.1.rejectionReason', null)
                ->where('invoices.1.amount.ves', null));
    }

    /**
     * `overdue` se decide con el día **del negocio**: vence el 2 y en
     * Kiritimati (+14) ya es el 3 a las 12:00 de la plataforma.
     */
    public function test_overdue_is_decided_in_the_business_calendar(): void
    {
        CarbonImmutable::setTestNow('2026-11-02 12:00:00');
        $this->assertSame('2026-11-02', CarbonImmutable::now()->toDateString(), 'En la plataforma todavía no ha vencido.');
        $this->billedOrganization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();
        $this->invoiceFor(['due_date' => '2026-11-02']);

        $this->actingAs($this->billedOwner)->get('/backoffice/suscripcion/facturas')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('invoices.0.status', 'overdue'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'method' => 'pago_movil',
            'bank' => 'Banesco',
            'reference' => '0123456789',
            'paidAt' => '2026-11-02',
            'amount' => '637.50',
            'currency' => 'VES',
            'file' => $this->receiptImage(),
        ];
    }
}
