<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\Organization;
use App\Models\PlatformActivity;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class VoidInvoiceActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization] = $this->businessWithOwner();
    }

    public function test_voiding_an_open_invoice_and_refusing_a_paid_one(): void
    {
        $operator = $this->asOperator();
        [$open, $paid] = $this->withinOrganization($this->organization, fn (): array => [
            SubscriptionInvoice::factory()->create(),
            SubscriptionInvoice::factory()->create(['status' => InvoiceStatus::Paid, 'paid_cents' => 1_500]),
        ]);
        $receipt = $this->withinOrganization($this->organization, fn (): SubscriptionReceipt => SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $open->getKey()]));
        $this->withoutOrganization();

        $this->post('/plataforma/facturas/'.$paid->getKey().'/anular', ['reason' => 'Factura duplicada por error'])->assertSessionHasErrors('reason');
        $this->post('/plataforma/facturas/'.$open->getKey().'/anular', ['reason' => 'Factura duplicada por error'])->assertRedirect();

        $this->assertSame(InvoiceStatus::Void, $this->fresh($open)->status);
        $this->assertSame(InvoiceStatus::Paid, $this->fresh($paid)->status);
        $this->assertSame(ReceiptStatus::Rejected, SubscriptionReceipt::query()->withoutTenantScope()->findOrFail($receipt->getKey())->status);
        $this->assertSame(1, PlatformActivity::query()->where('action', 'invoice.voided')->where('platform_user_id', $operator->getKey())->count());
    }

    public function test_the_inbox_and_approving_twice(): void
    {
        $operator = $this->asOperator();
        [$old, $new] = $this->withinOrganization($this->organization, function (): array {
            $invoice = SubscriptionInvoice::factory()->create();
            $old = SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $invoice->getKey(), 'created_at' => now()->subHours(50)]);
            $new = SubscriptionReceipt::factory()->create(['subscription_invoice_id' => SubscriptionInvoice::factory()->create()->getKey(), 'created_at' => now()->subHour()]);

            return [$old, $new];
        });
        $this->withoutOrganization();

        $receipts = $this->get('/plataforma/facturas')->assertOk()->viewData('page')['props']['receipts'];
        $this->assertSame([$old->getKey(), $new->getKey()], array_column($receipts, 'id'));
        $this->assertSame([true, false], array_column($receipts, 'isLate'));

        $approve = fn () => $this->post('/plataforma/comprobantes/'.$old->getKey().'/aprobar', ['settledAmount' => '15.00', 'settledCurrency' => 'USD']);
        $approve()->assertSessionHasNoErrors();
        $approve()->assertSessionHasErrors('receipt');

        $this->assertSame($operator->getKey(), SubscriptionReceipt::query()->withoutTenantScope()->findOrFail($old->getKey())->reviewed_by);
        $this->assertSame(1, PlatformActivity::query()->where('action', 'invoice.receipt_approved')->count());
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $this->asOperator();
        $receipt = $this->withinOrganization($this->organization, fn (): SubscriptionReceipt => SubscriptionReceipt::factory()->create(['subscription_invoice_id' => SubscriptionInvoice::factory()->create()->getKey()]));
        $this->withoutOrganization();

        $this->post('/plataforma/comprobantes/'.$receipt->getKey().'/rechazar', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/plataforma/comprobantes/'.$receipt->getKey().'/rechazar', ['reason' => 'La referencia no aparece en el banco'])->assertSessionHasNoErrors();

        $this->assertSame(ReceiptStatus::Rejected, SubscriptionReceipt::query()->withoutTenantScope()->findOrFail($receipt->getKey())->status);
    }

    private function fresh(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        return SubscriptionInvoice::query()->withoutTenantScope()->findOrFail($invoice->getKey());
    }
}
