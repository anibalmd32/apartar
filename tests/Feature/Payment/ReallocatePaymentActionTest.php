<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\ReallocatePaymentAction;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\AllocationPlan;
use App\Domain\Payment\InvalidReallocationException;
use App\Domain\Payment\PaymentMethod;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class ReallocatePaymentActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private const REASON = 'El cliente pactó abonar la cuota siguiente.';

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->staff = $this->within(fn (): User => User::factory()->create());
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_moves_the_allocation_and_keeps_the_balance(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '60.00');
        $paymentBefore = (array) DB::table('payments')->where('id', $payment->getKey())->first();
        $balanceBefore = $this->balanceOf($layaway);
        CarbonImmutable::setTestNow('2026-09-20 15:00:00');

        $plan = $this->reallocate($payment, [1 => 3_000, 3 => 3_000]);

        $this->assertSame(6_000, $plan->allocatedCents());
        $this->assertSame([[1, 3_000, 'paid'], [2, 0, 'pending'], [3, 3_000, 'paid'], [4, 0, 'pending'], [5, 0, 'pending']], $this->installmentsOf($layaway));
        $this->assertSame([[1, 3_000], [3, 3_000]], $this->allocationsOf($payment));
        $this->assertSame(['2026-09-10 10:00:00', null, '2026-09-10 10:00:00'], DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->limit(3)->pluck('paid_at')->all());
        $this->assertSame($paymentBefore, (array) DB::table('payments')->where('id', $payment->getKey())->first(), 'El pago no se toca.');
        $this->assertSame([$balanceBefore['paid_cents'], $balanceBefore['remaining_cents'], '2026-10-10'], array_values(array_intersect_key($this->balanceOf($layaway), array_flip(['paid_cents', 'remaining_cents', 'next_due_date']))));

        $changed = $this->within(fn (): array => AuditEntry::query()->where('event', AuditEvent::PaymentReallocated->value)->sole()->properties?->toArray()['changed'] ?? []);
        $this->assertSame([['cents' => 3_000, 'installment' => 1], ['cents' => 3_000, 'installment' => 2]], $changed['allocations']['before'] ?? null);
        $this->assertSame([['cents' => 3_000, 'installment' => 1], ['cents' => 3_000, 'installment' => 3]], $changed['allocations']['after'] ?? null);
        $this->assertSame(self::REASON, $changed['reason']['after'] ?? null);
    }

    public function test_each_refusal_says_why_and_writes_nothing(): void
    {
        $layaway = $this->activeLayaway();
        $other = $this->activeLayaway();
        $payment = $this->pay($layaway, '60.00');
        $foreignInstallment = (int) DB::table('installments')->where('layaway_id', $other->getKey())->where('number', 3)->value('id');
        $before = [$this->installmentsOf($layaway), $this->allocationsOf($payment)];

        $this->assertRefused('faltan $10,00', fn () => $this->reallocate($payment, [1 => 3_000, 3 => 2_000]));
        $this->assertRefused('sobran $5,00', fn () => $this->reallocate($payment, [1 => 3_000, 3 => 3_500]));
        $this->assertRefused('no es de este apartado', fn () => $this->within(fn () => app(ReallocatePaymentAction::class)->handle(
            $payment,
            [['installmentId' => $this->installmentId($layaway, 1), 'cents' => 3_000], ['installmentId' => $foreignInstallment, 'cents' => 3_000]],
            $this->staff,
            self::REASON,
        )));
        $this->assertRefused('La cuota 1 admite como mucho $30,00', fn () => $this->reallocate($payment, [1 => 6_000]));
        $this->assertRefused('es el mismo', fn () => $this->reallocate($payment, [2 => 3_000, 1 => 3_000]));
        $this->assertRefused('dos veces', fn () => $this->within(fn () => app(ReallocatePaymentAction::class)->handle(
            $payment,
            [['installmentId' => $this->installmentId($layaway, 3), 'cents' => 3_000], ['installmentId' => $this->installmentId($layaway, 3), 'cents' => 3_000]],
            $this->staff,
            self::REASON,
        )));
        $this->assertRefused('no está repartido', fn () => $this->reallocate($this->downPaymentOf($layaway), [1 => 5_000]));

        $this->assertSame($before, [$this->installmentsOf($layaway), $this->allocationsOf($payment)]);
        $this->assertSame(0, $this->within(fn (): int => AuditEntry::query()->where('event', AuditEvent::PaymentReallocated->value)->count()));
    }

    public function test_the_room_of_an_installment_counts_the_other_payments(): void
    {
        $layaway = $this->activeLayaway();
        $this->pay($layaway, '45.00');
        $second = $this->pay($layaway, '30.00');
        $this->assertSame([[2, 1_500], [3, 1_500]], $this->allocationsOf($second));

        $this->assertRefused('La cuota 2 admite como mucho $15,00', fn () => $this->reallocate($second, [2 => 3_000]));

        $this->reallocate($second, [2 => 1_500, 4 => 1_500]);
        $this->assertSame([[1, 3_000, 'paid'], [2, 3_000, 'paid'], [3, 0, 'pending'], [4, 1_500, 'partially_paid'], [5, 0, 'pending']], $this->installmentsOf($layaway));
    }

    public function test_uncovering_an_overdue_installment_returns_the_layaway_to_overdue(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '30.00');
        CarbonImmutable::setTestNow('2026-10-01 10:00:00');

        $this->reallocate($payment, [2 => 3_000]);

        $this->assertSame('overdue', $this->balanceOf($layaway)['status']);
        $this->assertSame([1, 0, 'overdue'], $this->installmentsOf($layaway)[0]);
        $this->assertSame('active → overdue', $this->within(fn (): mixed => AuditEntry::query()->where('event', AuditEvent::PaymentReallocated->value)->sole()->properties?->toArray()['changed']['layaway_status']['after'] ?? null));

        $this->reallocate($payment, [1 => 3_000]);

        $this->assertSame('active', $this->balanceOf($layaway)['status']);
        $this->assertSame([1, 3_000, 'paid'], $this->installmentsOf($layaway)[0]);
    }

    public function test_an_installment_the_daily_pass_has_not_marked_yet_still_counts(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '30.00');
        $this->reallocate($payment, [4 => 3_000]);
        CarbonImmutable::setTestNow('2026-10-14 10:00:00');
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['status' => 'overdue']);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Overdue->value]);

        $this->reallocate($payment, [1 => 3_000]);

        $this->assertSame([[1, 3_000, 'paid'], [2, 0, 'overdue'], [3, 0, 'pending'], [4, 0, 'pending'], [5, 0, 'pending']], $this->installmentsOf($layaway));
        $this->assertSame('overdue', $this->balanceOf($layaway)['status']);
    }

    public function test_a_completed_layaway_stays_completed(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '149.50');
        $this->assertSame('completed', $this->balanceOf($layaway)['status']);
        $counters = DB::table('usage_counters')->where('organization_id', $this->organization->getKey())->get()->map(fn (object $row): array => (array) $row)->all();

        $this->reallocate($payment, [1 => 3_000, 2 => 3_000, 3 => 3_000, 4 => 2_950, 5 => 3_000]);

        $this->assertSame('completed', $this->balanceOf($layaway)['status']);
        $this->assertSame([4, 2_950, 'partially_paid'], $this->installmentsOf($layaway)[3]);
        $this->assertSame($counters, DB::table('usage_counters')->where('organization_id', $this->organization->getKey())->get()->map(fn (object $row): array => (array) $row)->all());
    }

    public function test_closed_layaways_short_reasons_and_strangers_are_refused(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '30.00');

        $this->assertInstanceOf(InvalidArgumentException::class, $this->refused(fn () => $this->reallocate($payment, [2 => 3_000], 'Corto')));

        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->create());
        $this->assertInstanceOf(AuthorizationException::class, $this->refused(fn () => $this->within(fn () => app(ReallocatePaymentAction::class)->handle(
            $payment,
            [['installmentId' => $this->installmentId($layaway, 2), 'cents' => 3_000]],
            $stranger,
            self::REASON,
        ))));

        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Cancelled->value]);
        $this->assertRefused('cancelado', fn () => $this->reallocate($payment, [2 => 3_000]));
        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Expired->value]);
        $this->assertRefused('expirado', fn () => $this->reallocate($payment, [2 => 3_000]));
        $this->assertSame([[1, 3_000]], $this->allocationsOf($payment));
    }

    /**
     * @param  array<int, int>  $centsByNumber
     */
    private function reallocate(Payment $payment, array $centsByNumber, string $reason = self::REASON): AllocationPlan
    {
        $layaway = $this->within(fn (): Layaway => Layaway::query()->findOrFail($payment->layaway_id));
        $allocations = [];

        foreach ($centsByNumber as $number => $cents) {
            $allocations[] = ['installmentId' => $this->installmentId($layaway, $number), 'cents' => $cents];
        }

        return $this->within(fn (): AllocationPlan => app(ReallocatePaymentAction::class)->handle($payment, $allocations, $this->staff, $reason));
    }

    private function pay(Layaway $layaway, string $amount): Payment
    {
        return $this->within(fn (): Payment => app(RegisterManualPaymentAction::class)->handle(
            $layaway,
            new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, Currency::USD, null, CarbonImmutable::now(), 'Pago recibido en tienda por Ana.', (string) Str::uuid()),
            $this->staff,
        ));
    }

    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->pay($layaway, '50.00');

        return $layaway;
    }

    private function downPaymentOf(Layaway $layaway): Payment
    {
        return $this->within(fn (): Payment => Payment::query()->where('layaway_id', $layaway->getKey())->orderBy('id')->firstOrFail());
    }

    private function installmentId(Layaway $layaway, int $number): int
    {
        return (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', $number)->value('id');
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function installmentsOf(Layaway $layaway): array
    {
        return DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['number', 'paid_cents', 'status'])
            ->map(fn (object $row): array => [(int) $row->number, (int) $row->paid_cents, (string) $row->status])->all();
    }

    /**
     * @return list<array{int, int}>
     */
    private function allocationsOf(Payment $payment): array
    {
        return DB::table('payment_allocations')->join('installments', 'installments.id', '=', 'payment_allocations.installment_id')
            ->where('payment_id', $payment->getKey())->orderBy('installments.number')
            ->get(['installments.number', 'payment_allocations.amount_cents'])
            ->map(fn (object $row): array => [(int) $row->number, (int) $row->amount_cents])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceOf(Layaway $layaway): array
    {
        return (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['status', 'paid_cents', 'remaining_cents', 'next_due_date']);
    }

    /**
     * @param  Closure(): mixed  $attempt
     */
    private function assertRefused(string $message, Closure $attempt): void
    {
        $exception = $this->refused($attempt);

        $this->assertInstanceOf(InvalidReallocationException::class, $exception);
        $this->assertStringContainsString($message, $exception->getMessage());
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

        $this->fail('Tenía que haberse rechazado.');
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
