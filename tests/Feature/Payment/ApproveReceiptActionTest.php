<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Layaway\DetectOverdueAction;
use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

final class ApproveReceiptActionTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'Asia/Tokyo']));
        $this->reviewer = $this->within(fn (): User => User::factory()->create(['name' => 'Ana Pérez']));

        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        ExchangeRate::factory()->create(['source' => RateSource::Parallel, 'rate' => '50.00000000', 'published_at' => CarbonImmutable::now()->subHour()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_applies_fifo_and_approves_the_receipt(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway, attributes: ['declared_amount' => '2550.00', 'declared_currency' => Currency::VES]);

        $applied = $this->approve($receipt, '2550.00', Currency::VES);

        $this->assertSame(6_000, $applied->payment->amount_cents_usd->cents);
        $this->assertSame([[1, 3_000, 'paid'], [2, 3_000, 'paid'], [3, 0, 'pending']], $this->installments($layaway, 3));
        $this->assertSame((int) $receipt->getKey(), $applied->payment->receipt_id);

        $stored = $this->within(fn (): Receipt => Receipt::query()->findOrFail($receipt->getKey()));
        $this->assertSame(ReceiptStatus::Approved, $stored->status);
        $this->assertSame($this->reviewer->getKey(), $stored->reviewed_by);
        $this->assertSame('2026-09-10 10:00:00', $stored->reviewed_at?->toDateTimeString());

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReceiptApproved->value)->latest('id')->firstOrFail());
        $after = $entry->properties?->toArray()['changed'] ?? [];
        $this->assertSame([['cents' => 3_000, 'installment' => 1], ['cents' => 3_000, 'installment' => 2]], $after['allocations']['after'] ?? null);
        $this->assertSame('bcv', $after['rate_source']['after'] ?? null);
        $this->assertSame($this->reviewer->getKey(), $entry->causer_id);
    }

    public function test_approving_the_down_payment_activates_the_layaway(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $receipt = $this->receiptFor($layaway, attributes: ['declared_amount' => '80.00', 'declared_currency' => Currency::USD]);

        $applied = $this->approve($receipt, '80.00', Currency::USD);

        $this->assertSame(LayawayStatus::PendingDownPayment, $applied->statusBefore);
        $this->assertSame(LayawayStatus::Active, $applied->statusAfter);
        $this->assertSame(5_000, $applied->downPaymentCents);
        $this->assertSame(5, DB::table('installments')->where('layaway_id', $layaway->getKey())->count());
        $this->assertSame([[1, 3_000, 'paid']], $this->installments($layaway, 1));
    }

    public function test_covering_the_overdue_installment_returns_the_layaway_to_active(): void
    {
        $layaway = $this->activeLayaway();
        $this->within(fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::parse('2026-09-29 12:00:00')));
        $this->assertSame('overdue', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        CarbonImmutable::setTestNow('2026-09-29 12:00:00');

        $applied = $this->approve($this->receiptFor($layaway), '30.00', Currency::USD);

        $this->assertSame(LayawayStatus::Overdue, $applied->statusBefore);
        $this->assertSame(LayawayStatus::Active, $applied->statusAfter);
    }

    public function test_within_the_tolerance_it_completes_and_frees_the_plan(): void
    {
        $this->within(function (): void {
            OrganizationSetting::query()->where('key', SettingKey::SettlementToleranceCents)->delete();
            OrganizationSetting::factory()->forKey(SettingKey::SettlementToleranceCents, 100)->create();
            app(Settings::class)->forget($this->organization);
        });
        $layaway = $this->activeLayaway();
        $counted = $this->activeLayaways();

        $applied = $this->approve($this->receiptFor($layaway), '149.50', Currency::USD);

        $this->assertSame(LayawayStatus::Completed, $applied->statusAfter);
        $this->assertSame(50, $this->fresh($layaway)->remaining_cents->cents);
        $this->assertSame($counted - 1, $this->activeLayaways());
    }

    public function test_an_excess_needs_an_explicit_confirmation(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);
        $before = $this->snapshotOf($layaway);

        $refused = $this->refused(fn () => $this->approve($receipt, '160.00', Currency::USD));

        $this->assertInstanceOf(UnconfirmedExcessException::class, $refused);
        $this->assertSame(1_000, $refused->unallocated->cents);
        $this->assertSame($before, $this->snapshotOf($layaway));
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));

        $this->assertInstanceOf(UnconfirmedExcessException::class, $this->refused(fn () => $this->approve($receipt, '160.00', Currency::USD, confirmedExcess: 500)));
        $this->assertSame($before, $this->snapshotOf($layaway), 'Se aprobó un sobrante de $10 confirmando $5.');
        $this->assertInstanceOf(UnconfirmedExcessException::class, $this->refused(fn () => $this->approve($receipt, '160.00', Currency::USD, confirmedExcess: 2_000)));
        $this->assertSame($before, $this->snapshotOf($layaway), 'Se aprobó un sobrante de $10 confirmando $20.');

        $applied = $this->approve($receipt, '160.00', Currency::USD, confirmedExcess: 1_000);

        $this->assertSame(1_000, $applied->plan->unallocatedCents);
        $this->assertSame(LayawayStatus::Completed, $applied->statusAfter);

        $after = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReceiptApproved->value)->latest('id')->firstOrFail())->properties?->toArray()['changed'] ?? [];
        $this->assertSame(1_000, $after['unallocated_cents']['after'] ?? null);
        $this->assertSame('160.00 USD', $after['settled']['after'] ?? null);
        $this->assertSame(16_000, $after['amount_cents_usd']['after'] ?? null);
        $this->assertSame('active → completed', $after['layaway_status']['after'] ?? null);
    }

    public function test_an_approval_with_a_stale_copy_is_refused_under_the_lock(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $stale = $this->within(fn (): Receipt => Receipt::query()->findOrFail($receipt->getKey()));
        $this->approve($receipt, '30.00', Currency::USD);
        $payments = DB::table('payments')->count();

        $refused = $this->refused(fn () => $this->approve($stale, '30.00', Currency::USD));

        $this->assertInstanceOf(ReceiptAlreadyProcessedException::class, $refused);
        $this->assertSame($payments, DB::table('payments')->count());
    }

    public function test_the_payment_freezes_what_was_settled_and_the_receipt_keeps_what_was_declared(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway(), attributes: ['declared_amount' => '850.00', 'declared_currency' => Currency::VES]);

        $applied = $this->approve($receipt, '20.00', Currency::USD);

        $payment = $this->within(fn (): Payment => Payment::query()->findOrFail($applied->payment->getKey()));
        $this->assertSame(['20.00', Currency::USD, '1.00000000', RateSource::Bcv], [$payment->declared_amount, $payment->declared_currency, $payment->rate_applied, $payment->rate_source]);
        $this->assertSame(['pago_movil', '2026-09-10'], [$payment->payment_method?->value, $payment->paid_on?->toDateString()], 'El pago no guardó cómo se pagó.');
        $this->assertSame(['850.00', 'VES'], [(string) DB::table('receipts')->where('id', $receipt->getKey())->value('declared_amount'), DB::table('receipts')->where('id', $receipt->getKey())->value('declared_currency')]);
    }

    public function test_a_hand_written_rate_is_ignored_with_dollars(): void
    {
        $applied = $this->approve($this->receiptFor($this->activeLayaway()), '30.00', Currency::USD, rate: '45.00');

        $payment = $this->within(fn (): Payment => Payment::query()->findOrFail($applied->payment->getKey()));
        $this->assertSame([RateSource::Bcv, '1.00000000'], [$payment->rate_source, $payment->rate_applied]);
        $this->assertNotNull($payment->rate_published_at);

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReceiptApproved->value)->latest('id')->firstOrFail());
        $this->assertArrayNotHasKey('rate_override', $entry->properties?->toArray()['changed'] ?? []);
    }

    public function test_a_second_approval_fails_with_the_name_of_who_approved_it(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->approve($receipt, '30.00', Currency::USD);
        $payments = DB::table('payments')->count();

        $refused = $this->refused(fn () => $this->approve($receipt, '30.00', Currency::USD));

        $this->assertInstanceOf(ReceiptAlreadyProcessedException::class, $refused);
        $this->assertSame('Este comprobante ya fue aprobado por Ana Pérez.', $refused->getMessage());
        $this->assertSame($payments, DB::table('payments')->count());
    }

    public function test_a_failure_after_applying_rolls_everything_back(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);
        $before = $this->snapshotOf($layaway);
        $payments = DB::table('payments')->count();
        $audited = DB::table('activity_log')->where('event', AuditEvent::ReceiptApproved->value)->count();

        Receipt::saving(function (Receipt $saving): void {
            if ($saving->status === ReceiptStatus::Approved) {
                throw new RuntimeException('El disco se llenó.');
            }
        });

        $refused = $this->refused(fn () => $this->approve($receipt, '60.00', Currency::USD));

        $this->assertSame('El disco se llenó.', $refused->getMessage());
        $this->assertSame($payments, DB::table('payments')->count());
        $this->assertSame(0, DB::table('payment_allocations')->whereIn('installment_id', DB::table('installments')->where('layaway_id', $layaway->getKey())->pluck('id'))->count());
        $this->assertSame($before, $this->snapshotOf($layaway));
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
        $this->assertSame($audited, DB::table('activity_log')->where('event', AuditEvent::ReceiptApproved->value)->count());
    }

    public function test_the_rate_is_the_current_one_of_the_organization_source(): void
    {
        $this->within(function (): void {
            OrganizationSetting::query()->where('key', SettingKey::ExchangeRateSource)->delete();
            OrganizationSetting::factory()->forKey(SettingKey::ExchangeRateSource, RateSource::Parallel->value)->create();
            app(Settings::class)->forget($this->organization);
        });

        $applied = $this->approve($this->receiptFor($this->activeLayaway()), '1500.00', Currency::VES);

        $payment = $this->within(fn (): Payment => Payment::query()->findOrFail($applied->payment->getKey()));
        $this->assertSame(RateSource::Parallel, $payment->rate_source);
        $this->assertSame('50.00000000', $payment->rate_applied);
        $this->assertSame(3_000, $payment->amount_cents_usd->cents);
    }

    public function test_a_rate_written_by_hand_is_frozen_as_manual_and_audited(): void
    {
        $applied = $this->approve($this->receiptFor($this->activeLayaway()), '1200.00', Currency::VES, rate: '40.00');

        $payment = $this->within(fn (): Payment => Payment::query()->findOrFail($applied->payment->getKey()));
        $this->assertSame(RateSource::Manual, $payment->rate_source);
        $this->assertNull($payment->rate_published_at);
        $this->assertSame(3_000, $payment->amount_cents_usd->cents);

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReceiptApproved->value)->latest('id')->firstOrFail());
        $this->assertSame('40.00', $entry->properties?->toArray()['changed']['rate_override']['after'] ?? null);
    }

    public function test_bolivars_without_a_rate_are_refused(): void
    {
        $layaway = $this->activeLayaway();
        DB::table('exchange_rates')->delete();
        Cache::flush();
        $receipt = $this->receiptFor($layaway);

        $refused = $this->refused(fn () => $this->approve($receipt, '2550.00', Currency::VES));

        $this->assertInstanceOf(MissingExchangeRateException::class, $refused);
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    public function test_a_receipt_of_a_layaway_that_no_longer_collects_is_refused(): void
    {
        $receipt = $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Completed));

        $this->assertInstanceOf(PaymentNotApplicableException::class, $this->refused(fn () => $this->approve($receipt, '30.00', Currency::USD)));
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    public function test_someone_of_another_organization_cannot_approve(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->expectException(AuthorizationException::class);

        $this->within(fn () => app(ApproveReceiptAction::class)->handle($receipt, new ApproveReceiptData('30.00', Currency::USD, null, null), $intruder));
    }

    public function test_it_works_with_the_organization_suspended(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->assertSame(3_000, $this->approve($receipt, '30.00', Currency::USD)->payment->amount_cents_usd->cents);
    }

    private function approve(Receipt $receipt, string $amount, Currency $currency, ?string $rate = null, ?int $confirmedExcess = null, ?string $note = null): AppliedPayment
    {
        return $this->within(fn (): AppliedPayment => app(ApproveReceiptAction::class)->handle(
            $receipt,
            new ApproveReceiptData($amount, $currency, $rate, $note, $confirmedExcess),
            $this->reviewer,
        ));
    }

    /**
     * @param  Closure(): mixed  $attempt
     */
    private function refused(Closure $attempt): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $exception) {
            return $exception;
        }

        $this->fail('La aprobación tenía que haberse rechazado.');
    }

    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->approve($this->receiptFor($layaway), '50.00', Currency::USD);

        return $layaway;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(Layaway $layaway): array
    {
        return [
            'layaway' => (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['status', 'paid_cents', 'remaining_cents', 'next_due_date']),
            'installments' => DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['paid_cents', 'status'])->map(fn (object $row): array => (array) $row)->all(),
            'payments' => DB::table('payments')->where('layaway_id', $layaway->getKey())->count(),
        ];
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function installments(Layaway $layaway, int $count): array
    {
        return DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->limit($count)->get(['number', 'paid_cents', 'status'])
            ->map(fn (object $row): array => [(int) $row->number, (int) $row->paid_cents, (string) $row->status])->all();
    }

    private function fresh(Layaway $layaway): Layaway
    {
        return $this->within(fn (): Layaway => Layaway::query()->findOrFail($layaway->getKey()));
    }

    private function activeLayaways(): int
    {
        return (int) $this->within(fn (): mixed => UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
