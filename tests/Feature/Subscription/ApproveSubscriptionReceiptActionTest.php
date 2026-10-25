<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\ApproveSubscriptionReceiptAction;
use App\Actions\Subscription\ApproveSubscriptionReceiptData;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BillsSubscriptions;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ApproveSubscriptionReceiptActionTest extends TestCase
{
    use BillsSubscriptions, ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-28 12:00:00');
        $this->setUpBilling();

        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        ExchangeRate::factory()->create(['source' => RateSource::Parallel, 'rate' => '50.00000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->billed(fn () => OrganizationSetting::factory()->forKey(SettingKey::ExchangeRateSource, RateSource::Parallel->value)->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_approval_settles_the_invoice_extends_the_period_and_lifts_the_suspension(): void
    {
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => CarbonImmutable::now()]));
        $this->billedOrganization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => CarbonImmutable::now()])->save();
        $receipt = $this->receiptFor($invoice = $this->invoiceFor());

        $this->approve($receipt, '15.00', Currency::USD);

        $invoice->refresh();
        $subscription = $this->billed(fn (): Subscription => Subscription::query()->firstOrFail());
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1_500, $invoice->paid_cents);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 00:00:00'), $subscription->current_period_start);
        $this->assertEquals(CarbonImmutable::parse('2026-12-01 00:00:00'), $subscription->current_period_end);
        $this->assertSame(OrganizationStatus::Active, $this->billedOrganization->fresh()?->status);
        $this->assertNull($this->billedOrganization->fresh()->suspended_at);
        $this->assertSame(1, $this->billed(fn (): int => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionInvoicePaid)->count()));
    }

    public function test_bolivars_are_converted_with_the_platform_rate_and_the_rate_is_frozen(): void
    {
        $receipt = $this->receiptFor($invoice = $this->invoiceFor());

        $this->approve($receipt, '637.50', Currency::VES);

        $receipt->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
        $this->assertSame(1_500, $receipt->settled_cents_usd);
        $this->assertSame('42.50000000', $receipt->rate_applied);
        $this->assertSame(RateSource::Bcv, $receipt->rate_source);
        $this->assertNotNull($receipt->rate_published_at);
        $this->assertSame(ReceiptStatus::Approved, $receipt->status);
    }

    public function test_a_rate_written_by_the_operator_is_used_as_manual(): void
    {
        $receipt = $this->receiptFor($this->invoiceFor());

        $this->approve($receipt, '600.00', Currency::VES, rate: '40.00');

        $receipt->refresh();
        $this->assertSame(1_500, $receipt->settled_cents_usd);
        $this->assertSame(RateSource::Manual, $receipt->rate_source);
        $this->assertNull($receipt->rate_published_at);
    }

    public function test_without_a_platform_rate_bolivars_cannot_be_settled(): void
    {
        DB::table('exchange_rates')->where('source', RateSource::Bcv)->delete();
        Cache::flush();
        $receipt = $this->receiptFor($invoice = $this->invoiceFor());

        try {
            $this->approve($receipt, '637.50', Currency::VES);
            $this->fail('Sin tasa no se concilia en bolívares.');
        } catch (MissingExchangeRateException $exception) {
            $this->assertStringContainsString('tasa', $exception->getMessage());
        }

        $this->assertSame(ReceiptStatus::PendingReview, $receipt->fresh()?->status);
        $this->assertSame(0, $invoice->fresh()?->paid_cents);
    }

    public function test_a_partial_payment_leaves_the_exact_shortfall_pending(): void
    {
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue]));
        $invoice = $this->invoiceFor();

        $this->approve($this->receiptFor($invoice), '10.00', Currency::USD);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame(500, $invoice->outstanding()->cents);
        $this->assertSame(SubscriptionStatus::PastDue, $this->billed(fn (): SubscriptionStatus => Subscription::query()->firstOrFail()->status));

        $this->approve($this->receiptFor($invoice), '5.00', Currency::USD);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
        $this->assertSame(SubscriptionStatus::Active, $this->billed(fn (): SubscriptionStatus => Subscription::query()->firstOrFail()->status));
    }

    public function test_the_second_receipt_of_a_paid_invoice_is_rejected(): void
    {
        $invoice = $this->invoiceFor();
        $first = $this->receiptFor($invoice);
        $second = $this->receiptFor($invoice);

        $this->approve($first, '15.00', Currency::USD);

        $second->refresh();
        $this->assertSame(ReceiptStatus::Rejected, $second->status);
        $this->assertSame(ApproveSubscriptionReceiptAction::ALREADY_PAID_REASON, $second->rejection_reason);

        foreach ([$first, $second] as $processed) {
            try {
                $this->approve($processed, '15.00', Currency::USD);
                $this->fail('Un comprobante resuelto no se aprueba otra vez.');
            } catch (ReceiptAlreadyProcessedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1_500, $invoice->fresh()?->paid_cents);
    }

    public function test_paying_keeps_a_scheduled_downgrade(): void
    {
        $free = Plan::factory()->create(['position' => 1, 'price_cents' => 0]);
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue, 'scheduled_plan_id' => $free->getKey(), 'scheduled_plan_at' => '2026-12-01 00:00:00']));

        $this->approve($this->receiptFor($this->invoiceFor()), '15.00', Currency::USD);

        $this->assertSame(SubscriptionStatus::Grace, $this->billed(fn (): SubscriptionStatus => Subscription::query()->firstOrFail()->status));
    }

    public function test_the_new_period_is_the_invoice_period_and_an_old_invoice_never_shrinks_it(): void
    {
        $this->billed(fn () => Subscription::query()->update(['current_period_end' => '2026-11-15 00:00:00']));

        $this->approve($this->receiptFor($this->invoiceFor()), '15.00', Currency::USD);

        $subscription = $this->billed(fn (): Subscription => Subscription::query()->firstOrFail());
        $this->assertEquals(CarbonImmutable::parse('2026-11-01 00:00:00'), $subscription->current_period_start);
        $this->assertEquals(CarbonImmutable::parse('2026-12-01 00:00:00'), $subscription->current_period_end);

        $old = $this->invoiceFor(['period_start' => '2026-09-01 00:00:00', 'period_end' => '2026-10-01 00:00:00', 'due_date' => '2026-09-01']);
        $this->approve($this->receiptFor($old), '15.00', Currency::USD);

        $this->assertEquals(CarbonImmutable::parse('2026-12-01 00:00:00'), $this->billed(fn (): mixed => Subscription::query()->firstOrFail()->current_period_end));
    }

    public function test_paying_the_renewal_of_a_scheduled_downgrade_extends_the_period(): void
    {
        $cheaper = Plan::factory()->create(['position' => 1, 'price_cents' => 900]);
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Grace, 'scheduled_plan_id' => $cheaper->getKey(), 'scheduled_plan_at' => '2026-11-01 00:00:00']));

        $this->approve($this->receiptFor($this->invoiceFor(['plan_id' => $cheaper->getKey(), 'amount_cents' => 900])), '9.00', Currency::USD);

        $subscription = $this->billed(fn (): Subscription => Subscription::query()->firstOrFail());
        $this->assertEquals(CarbonImmutable::parse('2026-12-01 00:00:00'), $subscription->current_period_end);
        $this->assertSame(SubscriptionStatus::Grace, $subscription->status);
    }

    public function test_another_overdue_invoice_keeps_the_suspension_and_a_closed_subscription_stays_closed(): void
    {
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Suspended]));
        $this->billedOrganization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->invoiceFor(['due_date' => '2026-10-01', 'issued_at' => '2026-09-24 06:00:00', 'period_start' => '2026-10-01 00:00:00', 'period_end' => '2026-11-01 00:00:00']);

        $this->approve($this->receiptFor($this->invoiceFor()), '15.00', Currency::USD);

        $this->assertSame(SubscriptionStatus::Suspended, $this->billed(fn (): SubscriptionStatus => Subscription::query()->firstOrFail()->status));
        $this->assertSame(OrganizationStatus::Suspended, $this->billedOrganization->fresh()?->status);

        $this->billed(fn () => SubscriptionInvoice::query()->update(['status' => InvoiceStatus::Void]));
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Cancelled]));
        $this->approve($this->receiptFor($this->invoiceFor(['period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-01-01 00:00:00'])), '15.00', Currency::USD);

        $this->assertSame(SubscriptionStatus::Cancelled, $this->billed(fn (): SubscriptionStatus => Subscription::query()->firstOrFail()->status));
    }

    public function test_paying_the_debt_of_the_plan_left_behind_extends_the_period(): void
    {
        $cheaper = Plan::factory()->create(['code' => 'basico', 'position' => 1, 'price_cents' => 900]);
        $this->billed(fn () => Subscription::query()->update(['plan_id' => $cheaper->getKey(), 'status' => SubscriptionStatus::PastDue]));

        $this->approve($this->receiptFor($this->invoiceFor()), '15.00', Currency::USD);

        $subscription = $this->billed(fn (): Subscription => Subscription::query()->firstOrFail());
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertEquals(CarbonImmutable::parse('2026-12-01 00:00:00'), $subscription->current_period_end);
        $this->assertTrue($subscription->plan_id === $cheaper->getKey(), 'Paga el período; el plan sigue siendo al que bajó.');
    }

    public function test_another_overdue_invoice_under_review_also_keeps_the_suspension(): void
    {
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Suspended]));
        $this->billedOrganization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->receiptFor($this->invoiceFor(['due_date' => '2026-10-01', 'issued_at' => '2026-09-24 06:00:00', 'period_start' => '2026-10-01 00:00:00', 'period_end' => '2026-11-01 00:00:00']));

        $this->approve($this->receiptFor($this->invoiceFor()), '15.00', Currency::USD);

        $this->assertSame(SubscriptionStatus::Suspended, $this->billed(fn (): SubscriptionStatus => Subscription::query()->firstOrFail()->status));
        $this->assertSame(OrganizationStatus::Suspended, $this->billedOrganization->fresh()?->status);
    }

    public function test_the_other_branches_of_an_approval(): void
    {
        $invoice = $this->invoiceFor();
        $partial = $this->receiptFor($invoice);
        $this->receiptFor($invoice);
        $elsewhere = $this->receiptFor($this->invoiceFor(['period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-01-01 00:00:00']));

        $this->approve($partial, '5.00', Currency::USD);

        $this->assertSame(InvoiceStatus::UnderReview, $invoice->fresh()?->status);
        $this->assertNull($invoice->fresh()->paid_at);

        $this->approve($this->receiptFor($invoice), '10.00', Currency::USD);
        $this->assertSame(ReceiptStatus::PendingReview, $elsewhere->fresh()?->status, 'Los de otra factura no son «ya pagada».');

        $void = $this->invoiceFor(['status' => InvoiceStatus::Void, 'period_start' => '2027-01-01 00:00:00', 'period_end' => '2027-02-01 00:00:00']);
        $receipt = $this->billed(fn (): SubscriptionReceipt => SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $void->getKey(), 'submitted_by' => $this->billedOwner->getKey()]));

        $this->expectException(InvoiceNotPayableException::class);
        $this->approve($receipt, '15.00', Currency::USD);
    }

    private function receiptFor(SubscriptionInvoice $invoice): SubscriptionReceipt
    {
        return $this->billed(function () use ($invoice): SubscriptionReceipt {
            $receipt = SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $invoice->getKey(), 'submitted_by' => $this->billedOwner->getKey()]);
            $invoice->forceFill(['status' => InvoiceStatus::UnderReview])->save();

            return $receipt;
        });
    }

    private function approve(SubscriptionReceipt $receipt, string $amount, Currency $currency, ?string $rate = null): SubscriptionInvoice
    {
        $this->withoutOrganization();

        return app(ApproveSubscriptionReceiptAction::class)->handle($receipt, new ApproveSubscriptionReceiptData($amount, $currency, $rate));
    }
}
