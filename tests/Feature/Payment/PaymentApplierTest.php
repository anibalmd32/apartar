<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\ApprovalPreview;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayActivated;
use App\Events\Layaway\LayawayCaughtUp;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentApplierTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->staff = $this->within(fn (): User => User::factory()->create());
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');

        $this->within(function (): void {
            OrganizationSetting::query()->where('key', SettingKey::ExchangeRateSource)->delete();
            OrganizationSetting::factory()->forKey(SettingKey::ExchangeRateSource, RateSource::Parallel->value)->create();
            app(Settings::class)->forget($this->organization);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_dollar_payment_freezes_rate_one_with_the_current_source(): void
    {
        $applied = $this->apply($this->activeLayaway(), new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD);

        $payment = $this->stored($applied);
        $this->assertSame(3_000, $payment->amount_cents_usd->cents);
        $this->assertSame('1.00000000', $payment->rate_applied);
        $this->assertSame(RateSource::Bcv, $payment->rate_source);
        $this->assertSame('2026-09-09 13:00:00', $payment->rate_published_at?->toDateTimeString());
        $this->assertSame(Currency::USD, $payment->declared_currency);
        $this->assertSame('30.00', $payment->declared_amount);
        $this->assertSame($this->staff->getKey(), $payment->registered_by);
        $this->assertSame('Recibido en tienda.', $payment->note);
        $this->assertSame('2026-09-10 10:00:00', $payment->settled_at->toDateTimeString());
    }

    public function test_the_rate_is_brought_to_the_column_scale_before_converting(): void
    {
        $configured = new RateSnapshot('36.123456789', RateSource::Manual, CarbonImmutable::parse('2026-09-08 09:00:00'), CarbonImmutable::parse('2026-09-08 09:00:00'));

        $applied = $this->apply($this->activeLayaway(), new Money(361_235, Currency::VES), $configured, '3612.35', Currency::VES);

        $this->assertSame('36.12345679', $applied->payment->getAttributes()['rate_applied']);
        $this->assertSame('36.12345679', $this->stored($applied)->rate_applied);
        $this->assertSame(10_000, $this->stored($applied)->amount_cents_usd->cents);
        $this->assertSame('2026-09-08 09:00:00', $this->stored($applied)->rate_published_at?->toDateTimeString());
    }

    public function test_a_bolivar_payment_is_converted_with_the_snapshot_and_covers_fifo(): void
    {
        $layaway = $this->activeLayaway();

        $applied = $this->apply($layaway, new Money(255_000, Currency::VES), $this->bcv('42.50'), '2550.00', Currency::VES);

        $payment = $this->stored($applied);
        $this->assertSame(6_000, $payment->amount_cents_usd->cents);
        $this->assertSame('42.50000000', $payment->rate_applied);
        $this->assertSame(RateSource::Bcv, $payment->rate_source);
        $this->assertSame('2026-09-09 13:00:00', $payment->rate_published_at?->toDateTimeString());
        $this->assertSame(Currency::VES, $payment->declared_currency);
        $this->assertSame('2550.00', $payment->declared_amount);

        $this->assertSame([[1, 3_000, 'paid'], [2, 3_000, 'paid'], [3, 0, 'pending']], $this->installments($layaway, 3));
        $this->assertSame(2, $this->within(fn (): int => PaymentAllocation::query()->where('payment_id', $payment->getKey())->count()));

        $row = $this->fresh($layaway);
        $this->assertSame(11_000, $row->paid_cents->cents);
        $this->assertSame('2026-10-25', $row->next_due_date?->toDateString());
        $this->assertNull($this->diff($layaway));
    }

    public function test_a_manual_rate_is_frozen_as_manual_without_publication_date(): void
    {
        $applied = $this->apply($this->activeLayaway(), new Money(150_000, Currency::VES), new ManualRate('50'), '1500.00', Currency::VES);

        $payment = $this->stored($applied);
        $this->assertSame(3_000, $payment->amount_cents_usd->cents);
        $this->assertSame('50.00000000', $payment->rate_applied);
        $this->assertSame(RateSource::Manual, $payment->rate_source);
        $this->assertNull($payment->rate_published_at);
    }

    public function test_a_conversion_to_zero_cents_is_refused_before_writing(): void
    {
        $layaway = $this->activeLayaway();

        try {
            $this->apply($layaway, new Money(1, Currency::VES), $this->bcv('42.50'), '0.01', Currency::VES);
            $this->fail('Se concilió un pago de cero centavos.');
        } catch (PaymentNotApplicableException $exception) {
            $this->assertStringContainsString('no llega a un centavo', $exception->getMessage());
        }

        $this->assertSame(1, $this->paymentsOf($layaway));
        $this->assertSame(5_000, $this->fresh($layaway)->paid_cents->cents);
    }

    public function test_bolivars_need_a_rate_and_dollars_do_not(): void
    {
        $layaway = $this->activeLayaway();

        try {
            $this->apply($layaway, new Money(127_500, Currency::VES), null, '1275.00', Currency::VES);
            $this->fail('Se concilió un pago en bolívares sin tasa.');
        } catch (MissingExchangeRateException) {
        }

        $payment = $this->stored($this->apply($layaway, new Money(3_000), null, '30.00', Currency::USD));
        $this->assertSame('1.00000000', $payment->rate_applied);
        $this->assertSame(RateSource::Parallel, $payment->rate_source);
        $this->assertNull($payment->rate_published_at);
    }

    public function test_the_down_payment_activates_and_the_excess_goes_to_the_first_installments(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $applied = $this->apply($layaway, new Money(11_000), $this->bcv('42.50'), '110.00', Currency::USD);

        $this->assertSame(LayawayStatus::PendingDownPayment, $applied->statusBefore);
        $this->assertSame(LayawayStatus::Active, $applied->statusAfter);
        $this->assertTrue($applied->activated());
        $this->assertSame(5_000, $applied->downPaymentCents);
        $this->assertSame(6_000, $applied->plan->allocatedCents());
        $this->assertSame(0, $applied->plan->unallocatedCents);

        $this->assertSame([[1, 3_000, 'paid'], [2, 3_000, 'paid'], [3, 0, 'pending'], [4, 0, 'pending'], [5, 0, 'pending']], $this->installments($layaway, 5));
        $this->assertSame(11_000, $layaway->paid_cents->cents);
        $this->assertSame(LayawayStatus::Active, $layaway->status);
        $this->assertNotNull($this->within(fn (): ?CarbonImmutable => Installment::query()->where('layaway_id', $layaway->getKey())->where('number', 1)->firstOrFail()->paid_at?->toImmutable()));
        $this->assertNull($this->diff($layaway));
    }

    public function test_exactly_the_down_payment_activates_without_allocations(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $applied = $this->apply($layaway, new Money(5_000), $this->bcv('42.50'), '50.00', Currency::USD);

        $this->assertSame([], $applied->plan->allocations);
        $this->assertSame(0, $applied->plan->unallocatedCents);
        $this->assertSame(5_000, $applied->downPaymentCents);
        $this->assertSame('2026-09-25', $this->fresh($layaway)->next_due_date?->toDateString());
        $this->assertNull($this->diff($layaway));
    }

    public function test_less_than_the_down_payment_is_credited_to_it_and_the_layaway_keeps_waiting(): void
    {
        $layaway = $this->layawayIn($this->organization);
        Event::fake([LayawayActivated::class]);

        $applied = $this->apply($layaway, new Money(2_000), $this->bcv('42.50'), '20.00', Currency::USD);

        $this->assertSame(LayawayStatus::PendingDownPayment, $applied->statusAfter);
        $this->assertFalse($applied->activated());
        $this->assertSame([2_000, 0, 3_000], [$applied->downPaymentCents, $applied->downPaymentPaidBeforeCents, $applied->downPaymentMissingCents]);
        $this->assertSame('abono a la inicial de $20,00, faltan $30,00', $applied->downPayment()->label());
        $this->assertSame([], $applied->plan->allocations);
        $this->assertSame(0, $this->stored($applied)->unallocated_cents->cents);

        $row = $this->fresh($layaway);
        $this->assertSame(LayawayStatus::PendingDownPayment, $row->status);
        $this->assertNull($row->activated_at);
        $this->assertSame([2_000, 18_000], [$row->paid_cents->cents, $row->remaining_cents->cents]);
        $this->assertNull($row->next_due_date);
        $this->assertSame(1, $this->paymentsOf($layaway));
        $this->assertSame(0, $this->within(fn (): int => Installment::query()->where('layaway_id', $layaway->getKey())->count()));
        $this->assertNull($this->diff($layaway));
        Event::assertNotDispatched(LayawayActivated::class);
    }

    public function test_the_payment_that_completes_the_down_payment_activates_and_sends_the_rest_fifo(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->apply($layaway, new Money(2_000), $this->bcv('42.50'), '20.00', Currency::USD);
        $second = $this->apply($layaway, new Money(1_000), $this->bcv('42.50'), '10.00', Currency::USD);
        $this->assertSame([1_000, 2_000, 2_000], [$second->downPaymentCents, $second->downPaymentPaidBeforeCents, $second->downPaymentMissingCents]);
        $this->assertSame(LayawayStatus::PendingDownPayment, $this->fresh($layaway)->status);
        $this->assertSame(3_000, $this->fresh($layaway)->paid_cents->cents);
        $this->assertNull($this->diff($layaway));

        $third = $this->apply($layaway, new Money(6_000), $this->bcv('42.50'), '60.00', Currency::USD);

        $this->assertTrue($third->activated());
        $this->assertSame([2_000, 3_000, 0], [$third->downPaymentCents, $third->downPaymentPaidBeforeCents, $third->downPaymentMissingCents]);
        $this->assertSame('abono de $20,00 que completa la inicial', $third->downPayment()->label());
        $this->assertSame(4_000, $third->plan->allocatedCents());
        $this->assertSame([[1, 3_000, 'paid'], [2, 1_000, 'partially_paid'], [3, 0, 'pending']], $this->installments($layaway, 3));

        $row = $this->fresh($layaway);
        $this->assertSame(LayawayStatus::Active, $row->status);
        $this->assertSame([9_000, 11_000], [$row->paid_cents->cents, $row->remaining_cents->cents]);
        $this->assertSame('2026-10-10', $row->next_due_date?->toDateString());
        $this->assertSame(15_000, $this->within(fn (): int => (int) Installment::query()->where('layaway_id', $layaway->getKey())->sum('amount_cents')));
        $this->assertNull($this->diff($layaway));
    }

    public function test_exactly_the_missing_down_payment_activates_without_allocations(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->apply($layaway, new Money(4_999), $this->bcv('42.50'), '49.99', Currency::USD);
        $applied = $this->apply($layaway, new Money(1), $this->bcv('42.50'), '0.01', Currency::USD);

        $this->assertSame(LayawayStatus::Active, $applied->statusAfter);
        $this->assertSame(1, $applied->downPaymentCents);
        $this->assertSame([], $applied->plan->allocations);
        $this->assertSame([5_000, 15_000], [$this->fresh($layaway)->paid_cents->cents, $this->fresh($layaway)->remaining_cents->cents]);
        $this->assertNull($this->diff($layaway));
    }

    public function test_a_partial_down_payment_followed_by_an_overpayment_completes_and_keeps_the_excess(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->apply($layaway, new Money(1_500), $this->bcv('42.50'), '15.00', Currency::USD);
        $applied = $this->apply($layaway, new Money(19_000), $this->bcv('42.50'), '190.00', Currency::USD);

        $this->assertSame(LayawayStatus::Completed, $applied->statusAfter);
        $this->assertSame([3_500, 15_000, 500], [$applied->downPaymentCents, $applied->plan->allocatedCents(), $applied->plan->unallocatedCents]);
        $this->assertSame([20_000, 0], [$this->fresh($layaway)->paid_cents->cents, $this->fresh($layaway)->remaining_cents->cents]);
        $this->assertNull($this->diff($layaway));
    }

    public function test_a_zero_financed_layaway_completes_and_keeps_the_excess_unallocated(): void
    {
        $layaway = $this->layawayIn($this->organization, terms: new LayawayTerms(new Money(20_000), new Money(20_000), 1, 5, 15));

        $applied = $this->apply($layaway, new Money(20_500), $this->bcv('42.50'), '205.00', Currency::USD);

        $this->assertSame(LayawayStatus::Completed, $applied->statusAfter);
        $this->assertSame(500, $applied->plan->unallocatedCents);
        $this->assertSame(500, $this->stored($applied)->unallocated_cents->cents);
        $this->assertSame(0, $this->fresh($layaway)->remaining_cents->cents);
        $this->assertNull($this->diff($layaway));
    }

    public function test_an_overpayment_completes_keeps_the_excess_and_frees_the_plan(): void
    {
        $layaway = $this->activeLayaway();
        $counted = $this->activeLayaways();

        $applied = $this->apply($layaway, new Money(16_000), $this->bcv('42.50'), '160.00', Currency::USD);

        $this->assertSame(1_000, $applied->plan->unallocatedCents);
        $this->assertSame(LayawayStatus::Completed, $applied->statusAfter);
        $this->assertSame(20_000, $this->fresh($layaway)->paid_cents->cents);
        $this->assertSame($counted - 1, $this->activeLayaways());
        $this->assertNull($this->diff($layaway));
    }

    public function test_installment_status_uses_the_business_day_and_the_layaway_follows(): void
    {
        $layaway = $this->activeLayaway();
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $this->apply($layaway, new Money(1_000), $this->bcv('42.50'), '10.00', Currency::USD);
        $this->assertSame([[1, 1_000, 'overdue']], $this->installments($layaway, 1));
        $this->assertNull($this->paidAtOf($layaway, 1), 'Una cuota a medias no está saldada.');
        $this->assertSame(LayawayStatus::Overdue, $this->fresh($layaway)->status);

        $applied = $this->apply($layaway, new Money(2_000), $this->bcv('42.50'), '20.00', Currency::USD);
        $this->assertSame([[1, 3_000, 'paid']], $this->installments($layaway, 1));
        $this->assertSame('2026-09-28 12:00:00', $this->paidAtOf($layaway, 1));
        $this->assertSame(LayawayStatus::Overdue, $applied->statusBefore);
        $this->assertSame(LayawayStatus::Active, $applied->statusAfter);
    }

    public function test_covering_one_overdue_installment_does_not_hide_another_one_past_grace(): void
    {
        $layaway = $this->activeLayaway();
        $this->within(fn () => app(\App\Actions\Layaway\DetectOverdueAction::class)->handle(CarbonImmutable::parse('2026-09-29 12:00:00')));
        $this->assertSame([[1, 0, 'overdue'], [2, 0, 'pending']], $this->installments($layaway, 2));

        CarbonImmutable::setTestNow('2026-10-13 14:00:00');
        Event::fake([LayawayCaughtUp::class]);

        $applied = $this->apply($layaway, new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD);

        $this->assertSame([[1, 3_000, 'paid'], [2, 0, 'overdue']], $this->installments($layaway, 2));
        $this->assertSame(LayawayStatus::Overdue, $applied->statusAfter);
        Event::assertNotDispatched(LayawayCaughtUp::class);
    }

    public function test_the_preview_is_what_applying_does_and_writes_nothing(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);
        $before = [$this->installments($layaway, 5), $this->paymentsOf($layaway), $this->fresh($layaway)->paid_cents->cents];

        $preview = $this->within(fn (): ApprovalPreview => app(PaymentApplier::class)->preview(
            $layaway, new Money(276_250, Currency::VES), $this->bcv('42.50'), '2762.50', Currency::VES, $receipt,
        ));

        $this->assertSame([[1, 3_000, true], [2, 3_000, true], [3, 500, false]], array_map(
            fn (array $allocation): array => [$allocation['number'], $allocation['cents'], $allocation['covers']],
            $preview->allocations,
        ));
        $this->assertSame([6_500, '42.50000000', RateSource::Bcv, 0], [$preview->amount->cents, $preview->rate, $preview->rateSource, $preview->unallocatedCents]);
        $this->assertSame($before, [$this->installments($layaway, 5), $this->paymentsOf($layaway), $this->fresh($layaway)->paid_cents->cents]);
        $this->assertSame(LayawayStatus::Active, $layaway->status, 'La vista previa tocó el modelo que se le pasó.');

        $applied = $this->apply($layaway, new Money(276_250, Currency::VES), $this->bcv('42.50'), '2762.50', Currency::VES);
        $this->assertSame([6_500, 0], [$applied->payment->amount_cents_usd->cents, $applied->plan->unallocatedCents]);
        $this->assertSame([[1, 3_000, 'paid'], [2, 3_000, 'paid'], [3, 500, 'partially_paid']], $this->installments($layaway, 3));
    }

    public function test_the_preview_of_a_down_payment_activates_nothing(): void
    {
        $layaway = $this->layawayIn($this->organization);
        Event::fake([LayawayActivated::class]);

        $preview = $this->within(fn (): ApprovalPreview => app(PaymentApplier::class)->preview(
            $layaway, new Money(8_000), $this->bcv('42.50'), '80.00', Currency::USD, $this->receiptFor($layaway),
        ));

        $this->assertTrue($preview->activates());
        $this->assertSame([['number' => 1, 'cents' => 3_000, 'covers' => true]], $preview->allocations);
        $this->assertSame(LayawayStatus::Active, $preview->statusAfter);
        $this->assertSame(0, DB::table('installments')->where('layaway_id', $layaway->getKey())->count());
        $this->assertSame('pending_down_payment', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        Event::assertNotDispatched(LayawayActivated::class);
    }

    public function test_the_preview_of_a_partial_down_payment_says_what_will_be_missing(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->apply($layaway, new Money(1_000), $this->bcv('42.50'), '10.00', Currency::USD);

        $partial = $this->within(fn (): ApprovalPreview => app(PaymentApplier::class)->preview(
            $layaway, new Money(2_000), $this->bcv('42.50'), '20.00', Currency::USD, $this->receiptFor($layaway),
        ));

        $this->assertFalse($partial->activates());
        $this->assertSame(LayawayStatus::PendingDownPayment, $partial->statusAfter);
        $this->assertSame('Abonará $20,00 a la inicial y faltarán $20,00 para activar el apartado.', $partial->summary());
        $this->assertSame('$20,00', $partial->toArray()['downPaymentMissing']);

        $completing = $this->within(fn (): ApprovalPreview => app(PaymentApplier::class)->preview(
            $layaway, new Money(7_000), $this->bcv('42.50'), '70.00', Currency::USD, $this->receiptFor($layaway),
        ));

        $this->assertTrue($completing->activates());
        $this->assertSame('Completará la inicial con $40,00 y activará el apartado; se aplicará a la cuota 1.', $completing->summary());
        $this->assertSame(1, $this->paymentsOf($layaway));
        $this->assertSame(1_000, $this->fresh($layaway)->paid_cents->cents);
    }

    public function test_a_terminal_layaway_is_refused(): void
    {
        $layaway = $this->activeLayaway();
        $this->within(fn () => app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Cancelled));

        $this->expectException(PaymentNotApplicableException::class);
        $this->expectExceptionMessage('Cancelado');

        $this->apply($layaway, new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD);
    }

    public function test_the_idempotency_key_is_written_and_a_repeat_collides(): void
    {
        $layaway = $this->activeLayaway();
        $key = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f';

        $first = $this->apply($layaway, new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD, key: $key);
        $this->assertSame($key, $this->stored($first)->idempotency_key);

        try {
            $this->apply($layaway, new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD, key: $key);
            $this->fail('Dos pagos con la misma clave.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertSame(2, $this->paymentsOf($layaway));
        $this->assertSame(8_000, $this->fresh($layaway)->paid_cents->cents);
        $this->assertSame([[1, 3_000, 'paid'], [2, 0, 'pending']], $this->installments($layaway, 2));
    }

    public function test_the_payment_is_written_whole_in_its_insert(): void
    {
        $layaway = $this->activeLayaway();

        DB::enableQueryLog();
        $this->apply($layaway, new Money(4_000), $this->bcv('42.50'), '40.00', Currency::USD);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_starts_with(strtolower($sql), 'update `payments`'))));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_starts_with(strtolower($sql), 'insert into `payments`')));
    }

    public function test_receipt_and_registered_by(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);

        $fromReceipt = $this->within(fn (): AppliedPayment => app(PaymentApplier::class)->apply($layaway, new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD, $receipt, null, null));
        $this->assertSame($receipt->getKey(), $this->stored($fromReceipt)->receipt_id);
        $this->assertFalse($this->stored($fromReceipt)->isManual());

        try {
            $this->within(fn () => app(PaymentApplier::class)->apply($layaway, new Money(3_000), null, '30.00', Currency::USD, null, null, 'Sin firma'));
            $this->fail('Se registró un pago manual sin decir quién.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('registró', $exception->getMessage());
        }

        $other = $this->receiptFor($this->activeLayaway());
        $this->expectException(\LogicException::class);
        $this->within(fn () => app(PaymentApplier::class)->apply($layaway, new Money(3_000), null, '30.00', Currency::USD, $other, null, null));
    }

    public function test_a_failure_after_applying_rolls_everything_back(): void
    {
        $layaway = $this->layawayIn($this->organization);

        try {
            $this->within(fn () => DB::transaction(function () use ($layaway): void {
                $this->apply($layaway, new Money(11_000), $this->bcv('42.50'), '110.00', Currency::USD);

                throw new RuntimeException('El comprobante no se pudo marcar.');
            }));
        } catch (RuntimeException) {
        }

        $row = $this->fresh($layaway);
        $this->assertSame(LayawayStatus::PendingDownPayment, $row->status);
        $this->assertSame(0, $row->paid_cents->cents);
        $this->assertSame(0, $this->paymentsOf($layaway));
        $this->assertSame(0, $this->within(fn (): int => Installment::query()->where('layaway_id', $layaway->getKey())->count()));
    }

    public function test_a_non_positive_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->apply($this->activeLayaway(), new Money(0), $this->bcv('42.50'), '30.00', Currency::USD);
    }

    private function apply(Layaway $layaway, Money $settled, RateSnapshot|ManualRate|null $rate, string $declared, Currency $currency, ?string $key = null): AppliedPayment
    {
        return $this->within(fn (): AppliedPayment => app(PaymentApplier::class)->apply(
            $layaway, $settled, $rate, $declared, $currency, null, $this->staff, 'Recibido en tienda.', $key,
        ));
    }

    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->apply($layaway, new Money(5_000), $this->bcv('42.50'), '50.00', Currency::USD);

        return $layaway;
    }

    private function bcv(string $rate): RateSnapshot
    {
        return new RateSnapshot($rate, RateSource::Bcv, CarbonImmutable::parse('2026-09-09 13:00:00'), CarbonImmutable::parse('2026-09-09 13:30:00'));
    }

    private function stored(AppliedPayment $applied): Payment
    {
        return $this->within(fn (): Payment => Payment::query()->findOrFail($applied->payment->getKey()));
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function installments(Layaway $layaway, int $count): array
    {
        return $this->within(fn (): array => Installment::query()
            ->where('layaway_id', $layaway->getKey())
            ->orderBy('number')
            ->limit($count)
            ->get()
            ->map(fn (Installment $installment): array => [$installment->number, $installment->paid_cents->cents, $installment->status->value])
            ->all());
    }

    private function paidAtOf(Layaway $layaway, int $number): ?string
    {
        return $this->within(fn (): ?string => Installment::query()->where('layaway_id', $layaway->getKey())->where('number', $number)->firstOrFail()->paid_at?->toDateTimeString());
    }

    private function paymentsOf(Layaway $layaway): int
    {
        return $this->within(fn (): int => Payment::query()->where('layaway_id', $layaway->getKey())->count());
    }

    private function fresh(Layaway $layaway): Layaway
    {
        return $this->within(fn (): Layaway => Layaway::query()->findOrFail($layaway->getKey()));
    }

    private function diff(Layaway $layaway): mixed
    {
        return $this->within(fn (): mixed => app(LayawayBalanceRecalculator::class)->diff($layaway));
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
