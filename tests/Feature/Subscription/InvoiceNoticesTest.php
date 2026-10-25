<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\IssueInvoiceAction;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceNotices;
use App\Domain\Subscription\InvoiceNoticeType;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionInvoiceNotice;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use App\Notifications\InvoiceOverdueNotification;
use App\Notifications\SubscriptionReceiptReviewedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\BillsSubscriptions;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class InvoiceNoticesTest extends TestCase
{
    use BillsSubscriptions, ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-02 08:00:00');
        Notification::fake();
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_each_notice_goes_once_to_every_active_owner(): void
    {
        $invoice = $this->invoiceFor();
        [$second, $disabled, $member] = $this->billed(fn (): array => [
            User::factory()->owner()->create(),
            User::factory()->owner()->create(['disabled_at' => now()]),
            User::factory()->create(),
        ]);

        $this->assertTrue($this->notices()->sendOnce($invoice, InvoiceNoticeType::Overdue, CarbonImmutable::parse('2026-11-08')));
        $this->assertFalse($this->notices()->sendOnce($invoice, InvoiceNoticeType::Overdue, CarbonImmutable::parse('2026-11-08')));

        Notification::assertSentToTimes($this->billedOwner, InvoiceOverdueNotification::class, 1);
        Notification::assertSentToTimes($second, InvoiceOverdueNotification::class, 1);
        Notification::assertNotSentTo([$disabled, $member], InvoiceOverdueNotification::class);
        $this->assertSame(1, $this->billed(fn (): int => SubscriptionInvoiceNotice::query()->count()));
    }

    public function test_another_type_of_the_same_invoice_is_sent(): void
    {
        $invoice = $this->invoiceFor();

        $this->assertTrue($this->notices()->sendOnce($invoice, InvoiceNoticeType::Issued));
        $this->assertTrue($this->notices()->sendOnce($invoice, InvoiceNoticeType::Overdue, CarbonImmutable::parse('2026-11-08')));

        Notification::assertSentToTimes($this->billedOwner, InvoiceOverdueNotification::class, 2);
    }

    public function test_a_failure_to_queue_rolls_the_record_back(): void
    {
        $invoice = $this->invoiceFor();
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('cola caída'));

        try {
            $this->notices()->sendOnce($invoice, InvoiceNoticeType::Issued);
            $this->fail('El fallo de la cola sube.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->billed(fn (): int => SubscriptionInvoiceNotice::query()->count()));
    }

    public function test_an_invoice_whose_transaction_rolls_back_is_never_announced(): void
    {
        try {
            $this->billed(fn () => DB::transaction(function (): void {
                $this->issue();

                throw new RuntimeException('falla después de emitir');
            }));
        } catch (RuntimeException) {
        }

        Notification::assertNothingSent();
        $this->assertSame(0, $this->billed(fn (): int => SubscriptionInvoiceNotice::query()->count()));

        $this->billed(fn () => $this->issue());

        Notification::assertSentToTimes($this->billedOwner, InvoiceOverdueNotification::class, 1);
        Notification::assertSentTo($this->billedOwner, InvoiceOverdueNotification::class, fn (InvoiceOverdueNotification $notice): bool => $notice->type === InvoiceNoticeType::Issued
            && $notice->amount === '$15,00'
            && $notice->dueDate === '2026-12-01');
    }

    public function test_the_mail_says_the_amount_the_dates_and_that_customers_are_spared(): void
    {
        $notice = new InvoiceOverdueNotification(InvoiceNoticeType::Overdue, '$15,00', '2026-11-01', '2026-11-08', 'https://apartar.test/facturas');

        $mail = $notice->toMail($this->billedOwner);
        $text = implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

        $this->assertStringContainsString('$15,00', $text);
        $this->assertStringContainsString('2026-11-01', $text);
        $this->assertStringContainsString('2026-11-08', $text);
        $this->assertStringContainsString('Tus clientes no se ven afectados', $text);
        $this->assertSame('https://apartar.test/facturas', $mail->actionUrl);
    }

    public function test_the_review_outcome_is_told(): void
    {
        $invoice = $this->invoiceFor();
        $receipt = $this->billed(fn (): SubscriptionReceipt => SubscriptionReceipt::factory()->create([
            'subscription_invoice_id' => $invoice->getKey(),
            'submitted_by' => $this->billedOwner->getKey(),
            'status' => ReceiptStatus::Rejected,
            'rejection_reason' => 'No se ve la referencia.',
        ]));

        $this->notices()->reviewed($receipt, $invoice);
        $this->billed(fn () => $invoice->forceFill(['paid_cents' => 1_000])->save());
        $this->notices()->reviewed($receipt->forceFill(['status' => ReceiptStatus::Approved]), $invoice);
        $this->notices()->reviewed($receipt, $invoice->forceFill(['status' => InvoiceStatus::Paid]));

        $sent = Notification::sent($this->billedOwner, SubscriptionReceiptReviewedNotification::class)
            ->map(fn (SubscriptionReceiptReviewedNotification $notice): array => [$notice->outcome, $notice->outstanding, $notice->reason])
            ->all();

        $this->assertSame([
            [SubscriptionReceiptReviewedNotification::REJECTED, null, 'No se ve la referencia.'],
            [SubscriptionReceiptReviewedNotification::PARTIAL, '$5,00', null],
            [SubscriptionReceiptReviewedNotification::APPROVED, null, null],
        ], $sent);
    }

    private function notices(): InvoiceNotices
    {
        $this->withoutOrganization();

        return app(InvoiceNotices::class);
    }

    private function issue(): SubscriptionInvoice
    {
        return app(IssueInvoiceAction::class)->handle(
            Subscription::query()->firstOrFail(),
            $this->paidPlan,
            InvoiceKind::Renewal,
            CarbonImmutable::parse('2026-11-01 00:00:00'),
            CarbonImmutable::parse('2026-12-01 00:00:00'),
            CarbonImmutable::parse('2026-12-01'),
        );
    }
}
