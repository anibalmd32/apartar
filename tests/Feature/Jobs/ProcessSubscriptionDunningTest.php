<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Actions\Subscription\ApproveSubscriptionReceiptAction;
use App\Actions\Subscription\ApproveSubscriptionReceiptData;
use App\Actions\Subscription\RejectSubscriptionReceiptAction;
use App\Domain\Money\Currency;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Jobs\ProcessSubscriptionDunning;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionInvoiceNotice;
use App\Models\SubscriptionReceipt;
use App\Notifications\InvoiceOverdueNotification;
use App\Notifications\SubscriptionSuspendedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BillsSubscriptions;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProcessSubscriptionDunningTest extends TestCase
{
    use BillsSubscriptions, ReusesMigratedDatabase;

    private SubscriptionInvoice $invoice;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        Notification::fake();
        $this->setUpBilling();
        $this->invoice = $this->invoiceFor();
        $this->catalog = $this->billed(fn (): Catalog => Catalog::factory()->published()->create());
        $this->recalculateUsage($this->billedOrganization);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_full_sequence_of_states_by_date(): void
    {
        $this->at('2026-10-25 12:00:00');
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);
        $this->assertSame(['issued'], $this->noticesSent());

        $this->at('2026-11-01 20:00:00');
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status, 'El día del vencimiento todavía no está vencida.');

        $this->at('2026-11-02 06:30:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-08 23:59:59'), $this->subscription()->grace_ends_at);
        $this->assertSame(OrganizationStatus::Active, $this->billedOrganization->fresh()?->status, 'En la gracia no se pierde nada.');
        $this->assertSame(['issued', 'overdue'], $this->noticesSent());

        $this->at('2026-11-04 06:30:00');
        $this->assertSame(['issued', 'overdue'], $this->noticesSent(), 'A cuatro días no toca recordatorio.');

        $this->at('2026-11-05 06:30:00');
        $this->assertSame(['issued', 'overdue', 'grace_3_days'], $this->noticesSent());

        $this->at('2026-11-07 06:30:00');
        $this->assertSame(['issued', 'overdue', 'grace_3_days', 'grace_1_day'], $this->noticesSent());

        $this->at('2026-11-08 06:30:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status, 'El último día de gracia sigue en la gracia.');

        $this->at('2026-11-09 06:30:00');
        $this->assertSame(SubscriptionStatus::Suspended, $this->subscription()->status);
        $this->assertSame(OrganizationStatus::Suspended, $this->billedOrganization->fresh()?->status);
        $this->assertNull($this->catalog->fresh()?->published_at);
        $this->assertSame(['issued', 'overdue', 'grace_3_days', 'grace_1_day'], $this->noticesSent(), 'Suspendida, ya no hay gracia que recordar.');
        Notification::assertSentToTimes($this->billedOwner, SubscriptionSuspendedNotification::class, 1);
        Notification::assertSentToTimes($this->billedOwner, InvoiceOverdueNotification::class, 4);
    }

    public function test_paying_lifts_the_suspension_without_republishing_and_the_next_pass_agrees(): void
    {
        $this->at('2026-11-09 06:30:00');
        $this->assertSame(OrganizationStatus::Suspended, $this->billedOrganization->fresh()?->status);

        CarbonImmutable::setTestNow('2026-11-10 09:00:00');
        $this->approve($this->receiptFor($this->invoice), '15.00');

        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);
        $this->assertSame(OrganizationStatus::Active, $this->billedOrganization->fresh()?->status);
        $this->assertNull($this->catalog->fresh()?->published_at, 'Republicar es del owner.');

        $this->at('2026-11-10 10:00:00');
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);
        $this->assertSame(OrganizationStatus::Active, $this->billedOrganization->fresh()?->status);
    }

    public function test_two_passes_on_the_same_day_change_nothing_the_second_time(): void
    {
        $first = $this->at('2026-11-02 06:30:00');
        $second = $this->at('2026-11-02 18:00:00');

        $this->assertSame(1, $first['past_due']);
        $this->assertSame(['past_due' => 0, 'suspended' => 0, 'settled' => 0, 'notices' => 0], $second);
        $this->assertSame(['overdue'], $this->noticesSent());
    }

    public function test_a_receipt_under_review_freezes_the_grace_and_a_rejection_gives_a_day(): void
    {
        $receipt = $this->receiptFor($this->invoice);

        $this->at('2026-11-12 06:30:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status, 'Con el comprobante en revisión no se suspende.');

        CarbonImmutable::setTestNow('2026-11-12 10:00:00');
        $this->withoutOrganization();
        app(RejectSubscriptionReceiptAction::class)->handle($receipt, 'No se ve la referencia.');

        $this->at('2026-11-13 09:59:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status, 'Dentro de las 24 h del rechazo.');

        $this->at('2026-11-13 10:00:00');
        $this->assertSame(SubscriptionStatus::Suspended, $this->subscription()->status);
    }

    public function test_a_pass_that_did_not_run_for_weeks_catches_up(): void
    {
        $this->at('2026-11-20 06:30:00');

        $this->assertSame(SubscriptionStatus::Suspended, $this->subscription()->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-08 23:59:59'), $this->subscription()->grace_ends_at);
        $this->assertSame(['overdue'], $this->noticesSent(), 'Ni la emisión ya vencida ni los recordatorios de una gracia acabada se envían tarde.');
    }

    public function test_a_plan_without_price_is_not_pursued_and_its_suspension_is_lifted(): void
    {
        $this->at('2026-11-09 06:30:00');
        $free = Plan::factory()->create(['code' => 'gratis', 'position' => 1, 'price_cents' => 0]);
        $this->billed(fn () => Subscription::query()->update(['plan_id' => $free->getKey()]));
        Notification::fake();

        $this->at('2026-11-10 06:30:00');

        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);
        $this->assertSame(OrganizationStatus::Active, $this->billedOrganization->fresh()?->status);
        $this->assertSame(InvoiceStatus::Pending, $this->invoice->fresh()?->status, 'La factura queda registrada.');
        Notification::assertNothingSent();
    }

    public function test_the_scheduled_downgrade_survives_and_comes_back_as_grace(): void
    {
        $target = Plan::factory()->create(['code' => 'destino', 'position' => 1, 'price_cents' => 900]);
        $this->billed(fn () => Subscription::query()->update([
            'status' => SubscriptionStatus::Grace,
            'scheduled_plan_id' => $target->getKey(),
            'scheduled_plan_at' => '2026-12-01 00:00:00',
        ]));

        $this->at('2026-11-02 06:30:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status);
        $this->assertSame($target->getKey(), $this->subscription()->scheduled_plan_id);

        $this->billed(fn () => $this->invoice->forceFill(['status' => InvoiceStatus::Void])->save());
        $this->at('2026-11-03 06:30:00');
        $this->assertSame(SubscriptionStatus::Grace, $this->subscription()->status);
        $this->assertNull($this->subscription()->grace_ends_at);
    }

    public function test_an_invoice_issued_already_overdue_gets_its_grace_from_the_day_it_was_issued(): void
    {
        $this->billed(fn () => $this->invoice->forceFill(['status' => InvoiceStatus::Void])->save());
        CarbonImmutable::setTestNow('2026-11-15 06:00:00');
        $late = $this->invoiceFor(['due_date' => '2026-11-01', 'period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-01-01 00:00:00']);

        $this->at('2026-11-15 06:30:00');
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status, 'El día en que se emite no está vencida.');

        $this->at('2026-11-16 06:30:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-22 23:59:59'), $this->subscription()->grace_ends_at);
        $this->assertNotNull($late->fresh());
    }

    public function test_days_are_counted_in_the_business_calendar(): void
    {
        $this->billedOrganization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $this->at('2026-11-08 12:00:00');

        $this->assertSame(SubscriptionStatus::Suspended, $this->subscription()->status);
    }

    public function test_the_grace_end_is_stored_as_the_instant_in_the_business_zone(): void
    {
        $this->billedOrganization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $this->at('2026-11-01 12:00:00');

        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status);
        $this->assertEquals(CarbonImmutable::parse('2026-11-08 23:59:59', 'Pacific/Kiritimati'), $this->subscription()->grace_ends_at);
    }

    public function test_an_invoice_issued_today_in_the_business_zone_is_not_yet_a_debt(): void
    {
        $this->billedOrganization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();
        $this->billed(fn () => $this->invoice->forceFill(['status' => InvoiceStatus::Void])->save());
        $this->invoiceFor(['due_date' => '2026-10-01', 'issued_at' => '2026-11-15 08:00:00', 'period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-01-01 00:00:00']);

        $this->at('2026-11-15 11:00:00');
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);

        $this->at('2026-11-16 11:00:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status);
    }

    public function test_the_extension_counts_from_the_latest_review(): void
    {
        $this->reviewed($this->invoice, '2026-11-03 10:00:00');
        $this->reviewed($this->invoice, '2026-11-12 10:00:00');

        $this->at('2026-11-12 20:00:00');

        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status);
    }

    public function test_a_receipt_on_an_invoice_not_yet_due_does_not_freeze_the_grace(): void
    {
        $this->receiptFor($this->invoiceFor(['due_date' => '2026-12-01', 'period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-01-01 00:00:00']));

        $this->at('2026-11-09 06:30:00');

        $this->assertSame(SubscriptionStatus::Suspended, $this->subscription()->status);
    }

    public function test_a_half_suspended_organization_without_debt_is_lifted_by_the_pass(): void
    {
        $this->billed(fn () => $this->invoice->forceFill(['status' => InvoiceStatus::Void])->save());
        $this->billedOrganization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();

        $result = $this->at('2026-11-10 06:30:00');

        $this->assertSame(1, $result['settled']);
        $this->assertSame(OrganizationStatus::Active, $this->billedOrganization->fresh()?->status);
    }

    public function test_nothing_is_announced_without_a_price_nor_for_settled_invoices(): void
    {
        $free = Plan::factory()->create(['code' => 'gratis', 'position' => 1, 'price_cents' => 0]);
        $this->billed(fn () => Subscription::query()->update(['plan_id' => $free->getKey()]));
        $this->at('2026-10-26 06:30:00');
        $this->assertSame([], $this->noticesSent());

        $this->billed(fn () => Subscription::query()->update(['plan_id' => $this->paidPlan->getKey()]));
        $this->billed(fn () => $this->invoice->forceFill(['status' => InvoiceStatus::Paid, 'paid_cents' => 1_500])->save());
        $this->invoiceFor(['status' => InvoiceStatus::Void, 'period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-01-01 00:00:00']);
        $this->invoiceFor(['status' => InvoiceStatus::UnderReview, 'period_start' => '2027-01-01 00:00:00', 'period_end' => '2027-02-01 00:00:00']);
        $this->at('2026-10-27 06:30:00');

        $this->assertSame(['issued'], $this->noticesSent(), 'Solo la que todavía se debe.');
    }

    public function test_closed_organizations_and_cancelled_subscriptions_are_left_alone(): void
    {
        $this->billedOrganization->forceFill(['status' => OrganizationStatus::Closed])->save();
        $this->at('2026-11-20 06:30:00');
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);

        $this->billedOrganization->forceFill(['status' => OrganizationStatus::Active])->save();
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Cancelled]));
        $this->at('2026-11-20 06:30:00');
        $this->assertSame(SubscriptionStatus::Cancelled, $this->subscription()->status);
        $this->assertSame(OrganizationStatus::Active, $this->billedOrganization->fresh()?->status);
    }

    public function test_each_organization_on_its_own_and_a_failure_does_not_stop_the_pass(): void
    {
        $broken = Organization::factory()->create();
        $this->withinOrganization($broken, function () use ($broken): void {
            $invoice = new SubscriptionInvoice;
            $invoice->forceFill([
                'organization_id' => $broken->getKey(),
                'plan_id' => $this->paidPlan->getKey(),
                'kind' => 'renewal',
                'period_start' => '2026-11-01 00:00:00',
                'period_end' => '2026-12-01 00:00:00',
                'amount_cents' => 1_500,
                'paid_cents' => 0,
                'currency' => 'USD',
                'status' => InvoiceStatus::Pending,
                'due_date' => '2026-10-01',
                'issued_at' => '2026-09-25 10:00:00',
            ])->save();
        });

        $this->at('2026-11-09 06:30:00');

        $this->assertSame(SubscriptionStatus::Suspended, $this->subscription()->status);
        $this->assertSame(OrganizationStatus::Active, $broken->fresh()?->status);
    }

    public function test_it_is_scheduled_daily_after_the_renewals(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'subscriptions:process-dunning');

        $this->assertNotNull($event);
        $this->assertSame('30 6 * * *', $event->expression);
        $this->assertSame('default', (new ProcessSubscriptionDunning)->queue);
    }

    /**
     * @return array<string, int>
     */
    private function at(string $now): array
    {
        CarbonImmutable::setTestNow($now);
        $this->withoutOrganization();

        return app()->call([new ProcessSubscriptionDunning, 'handle']);
    }

    private function subscription(): Subscription
    {
        return $this->billed(fn (): Subscription => Subscription::query()->firstOrFail());
    }

    /**
     * @return list<string>
     */
    private function noticesSent(): array
    {
        return $this->billed(fn (): array => SubscriptionInvoiceNotice::query()
            ->orderBy('id')
            ->get()
            ->map(fn (SubscriptionInvoiceNotice $notice): string => $notice->type->value)
            ->all());
    }

    private function receiptFor(SubscriptionInvoice $invoice): SubscriptionReceipt
    {
        return $this->billed(function () use ($invoice): SubscriptionReceipt {
            $receipt = SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $invoice->getKey(), 'submitted_by' => $this->billedOwner->getKey()]);
            $invoice->forceFill(['status' => InvoiceStatus::UnderReview])->save();

            return $receipt;
        });
    }

    private function reviewed(SubscriptionInvoice $invoice, string $at): void
    {
        $this->billed(fn () => SubscriptionReceipt::factory()->create([
            'subscription_invoice_id' => $invoice->getKey(),
            'submitted_by' => $this->billedOwner->getKey(),
            'status' => ReceiptStatus::Rejected,
            'rejection_reason' => 'No se ve la referencia.',
            'reviewed_at' => $at,
        ]));
    }

    private function approve(SubscriptionReceipt $receipt, string $amount): void
    {
        $this->withoutOrganization();

        app(ApproveSubscriptionReceiptAction::class)->handle($receipt, new ApproveSubscriptionReceiptData($amount, Currency::USD, null));
    }
}
