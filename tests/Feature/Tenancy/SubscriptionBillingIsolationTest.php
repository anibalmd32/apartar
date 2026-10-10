<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Subscription\InvoiceStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use App\Queries\DashboardWarningsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Las facturas y los comprobantes de la suscripción contra la organización
 * vecina (`REQ-SUB-006`, `REQ-QAX-008` RB-2, `T-069`).
 *
 * Son tablas del tenant: el historial, el aviso del panel y el bloqueo de
 * duplicados de un negocio no pueden ver lo del vecino.
 */
final class SubscriptionBillingIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_neighbour_invoices_and_receipts_are_invisible(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1_500]);

        $ours = Organization::factory()->create();
        $owner = $this->withinOrganization($ours, function () use ($plan): User {
            Subscription::factory()->forPlan($plan)->paid()->create();

            return User::factory()->owner()->create();
        });

        $neighbour = $this->neighbourOrganization();
        $hash = $this->withinOrganization($neighbour, function () use ($plan): string {
            Subscription::factory()->forPlan($plan)->paid()->create();
            $invoice = SubscriptionInvoice::factory()->forPlan($plan)->create(['due_date' => CarbonImmutable::now()->subWeek()->toDateString(), 'status' => InvoiceStatus::Pending]);

            return SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $invoice->getKey()])->file_hash;
        });
        $this->withoutOrganization();

        $this->actingAs($owner)
            ->get('/backoffice/suscripcion/facturas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices', 0));

        $this->withinOrganization($ours, function () use ($ours, $hash): void {
            $kinds = array_column(app(DashboardWarningsQuery::class)->forOrganization($ours), 'kind');
            $this->assertNotContains('invoice_overdue', $kinds, 'La factura vencida del vecino no es de este negocio.');
            // Su comprobante no se lee desde aquí. Que cuente como duplicado sí
            // es a propósito (cobra la plataforma: `SubscriptionReceiptDuplicates`),
            // pero se pregunta desde `app/Queries/Platform`, no por el modelo.
            $this->assertSame(0, SubscriptionReceipt::query()->where('file_hash', $hash)->count(), 'Su comprobante no es de este negocio.');
        });
    }
}
