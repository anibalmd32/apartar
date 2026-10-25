<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\RejectSubscriptionReceiptAction;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\BillsSubscriptions;
use Tests\TestCase;

final class RejectSubscriptionReceiptActionTest extends TestCase
{
    use BillsSubscriptions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBilling();
    }

    public function test_a_rejection_keeps_the_invoice_pending_with_its_reason(): void
    {
        $invoice = $this->invoiceFor(['status' => InvoiceStatus::UnderReview]);
        $receipt = $this->receiptFor($invoice);

        $this->withoutOrganization();
        app(RejectSubscriptionReceiptAction::class)->handle($receipt, '  La referencia no aparece en el banco.  ');

        $receipt->refresh();
        $this->assertSame(ReceiptStatus::Rejected, $receipt->status);
        $this->assertSame('La referencia no aparece en el banco.', $receipt->rejection_reason);
        $this->assertNotNull($receipt->reviewed_at);
        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()?->status);
        $this->assertTrue($invoice->fresh()->status->acceptsReceipts());
    }

    public function test_another_receipt_under_review_keeps_the_invoice_under_review(): void
    {
        $invoice = $this->invoiceFor(['status' => InvoiceStatus::UnderReview]);
        $rejected = $this->receiptFor($invoice);
        $this->receiptFor($invoice);

        $this->withoutOrganization();
        app(RejectSubscriptionReceiptAction::class)->handle($rejected, 'Ilegible.');

        $this->assertSame(InvoiceStatus::UnderReview, $invoice->fresh()?->status);
    }

    public function test_a_reason_is_required_and_a_processed_receipt_is_final(): void
    {
        $receipt = $this->receiptFor($this->invoiceFor(['status' => InvoiceStatus::UnderReview]));
        $this->withoutOrganization();

        try {
            app(RejectSubscriptionReceiptAction::class)->handle($receipt, '   ');
            $this->fail('Rechazar exige motivo.');
        } catch (InvalidArgumentException) {
            $this->assertSame(ReceiptStatus::PendingReview, $receipt->fresh()?->status);
        }

        app(RejectSubscriptionReceiptAction::class)->handle($receipt, 'Ilegible.');

        $this->expectException(ReceiptAlreadyProcessedException::class);
        app(RejectSubscriptionReceiptAction::class)->handle($receipt, 'Otra vez.');
    }

    private function receiptFor(SubscriptionInvoice $invoice): SubscriptionReceipt
    {
        return $this->billed(fn (): SubscriptionReceipt => SubscriptionReceipt::factory()->create([
            'subscription_invoice_id' => $invoice->getKey(),
            'submitted_by' => $this->billedOwner->getKey(),
        ]));
    }
}
