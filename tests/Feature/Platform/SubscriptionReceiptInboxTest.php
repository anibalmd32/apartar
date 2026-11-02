<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Money\Currency;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\SubscriptionReceipt;
use App\Queries\Platform\SubscriptionReceiptInbox;
use Carbon\CarbonImmutable;
use Tests\Concerns\BillsSubscriptions;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SubscriptionReceiptInboxTest extends TestCase
{
    use BillsSubscriptions, ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-28 12:00:00');
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_el_monto_declarado_llega_al_estilo_venezolano_y_no_como_15_00_usd(): void
    {
        $this->receiptDeclaring('1234.50', Currency::USD);
        $this->receiptDeclaring('22231.38', Currency::VES);
        $this->withoutOrganization();

        $declared = array_column(app(SubscriptionReceiptInbox::class)->pending(), 'declared');

        $this->assertSame(['$1.234,50', 'Bs. 22.231,38'], $declared);
    }

    private function receiptDeclaring(string $amount, Currency $currency): void
    {
        $invoice = $this->invoiceFor();

        $this->billed(function () use ($invoice, $amount, $currency): void {
            SubscriptionReceipt::factory()->create([
                'subscription_invoice_id' => $invoice->getKey(),
                'submitted_by' => $this->billedOwner->getKey(),
                'declared_amount' => $amount,
                'declared_currency' => $currency,
            ]);
            $invoice->forceFill(['status' => InvoiceStatus::UnderReview])->save();
        });
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    }
}
