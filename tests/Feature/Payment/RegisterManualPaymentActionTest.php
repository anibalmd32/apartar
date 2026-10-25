<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

final class RegisterManualPaymentActionTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private const NOTE = 'Pago en efectivo recibido en tienda por Ana.';

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

    public function test_it_records_the_payment_without_a_receipt_and_activates(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $account = $this->within(fn (): PaymentAccount => PaymentAccount::factory()->create());

        $payment = $this->register($layaway, '80.00', Currency::USD, account: $account, paidAt: '2026-09-09');

        $stored = $this->within(fn (): Payment => Payment::query()->findOrFail($payment->getKey()));
        $this->assertNull($stored->receipt_id);
        $this->assertSame($this->staff->getKey(), $stored->registered_by);
        $this->assertSame(self::NOTE, $stored->note);
        $this->assertSame([PaymentMethod::EfectivoUsd, $account->getKey(), '2026-09-09'], [$stored->payment_method, $stored->payment_account_id, $stored->paid_on?->toDateString()]);
        $this->assertSame('active', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        $this->assertSame(3_000, (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->value('paid_cents'));

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::PaymentRecorded->value)->sole());
        $changed = $entry->properties?->toArray()['changed'] ?? [];
        $this->assertSame(self::NOTE, $changed['note']['after'] ?? null);
        $this->assertSame([
            'efectivo_usd', $account->getKey(), '2026-09-09', '80.00 USD', 8_000, 5_000, [['cents' => 3_000, 'installment' => 1]], 0, 'pending_down_payment → active',
        ], array_map(fn (string $field): mixed => $changed[$field]['after'] ?? null, [
            'payment_method', 'payment_account', 'paid_on', 'paid', 'amount_cents_usd', 'down_payment_cents', 'allocations', 'unallocated_cents', 'layaway_status',
        ]));
        $this->assertArrayNotHasKey('rate_override', $changed);
    }

    public function test_the_same_key_returns_the_same_payment(): void
    {
        $layaway = $this->activeLayaway();
        $key = (string) Str::uuid();

        $first = $this->register($layaway, '30.00', Currency::USD, key: $key);
        $second = $this->register($layaway, '30.00', Currency::USD, key: $key);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, DB::table('payments')->where('idempotency_key', $key)->count());
        $this->assertSame(3_000, (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->value('paid_cents'));
    }

    public function test_a_key_that_recorded_another_payment_is_refused(): void
    {
        $layaway = $this->activeLayaway();
        $other = $this->activeLayaway();
        $key = (string) Str::uuid();
        $this->register($layaway, '30.00', Currency::USD, key: $key);

        $this->assertInstanceOf(IdempotencyKeyReusedException::class, $this->refused(fn () => $this->register($layaway, '35.00', Currency::USD, key: $key)));
        $this->assertInstanceOf(IdempotencyKeyReusedException::class, $this->refused(fn () => $this->register($other, '30.00', Currency::USD, key: $key)));
        $this->assertInstanceOf(IdempotencyKeyReusedException::class, $this->refused(fn () => $this->register($layaway, '30.00', Currency::VES, key: $key)));

        $this->assertSame(3, DB::table('payments')->count());
        $this->assertSame(0, (int) DB::table('installments')->where('layaway_id', $other->getKey())->where('number', 1)->value('paid_cents'));
    }

    public function test_a_rate_written_by_hand_converts_bolivars_only(): void
    {
        $layaway = $this->activeLayaway();

        $bolivars = $this->stored($this->register($layaway, '1200.00', Currency::VES, rate: '40.00'));
        $dollars = $this->stored($this->register($layaway, '30.00', Currency::USD, rate: '45.00'));

        $this->assertSame([3_000, RateSource::Manual, '40.00000000'], [$bolivars->amount_cents_usd->cents, $bolivars->rate_source, $bolivars->rate_applied]);
        $this->assertSame([RateSource::Bcv, '1.00000000'], [$dollars->rate_source, $dollars->rate_applied]);

        $overrides = $this->within(fn (): array => AuditEntry::query()->where('event', AuditEvent::PaymentRecorded->value)->orderBy('id')->get()
            ->map(fn (AuditEntry $entry): mixed => $entry->properties?->toArray()['changed']['rate_override']['after'] ?? null)->all());
        $this->assertSame([null, '40.00', null], $overrides);
    }

    public function test_it_leaves_the_same_result_as_approving_a_receipt(): void
    {
        $approved = $this->activeLayaway();
        $manual = $this->activeLayaway();
        $account = $this->within(fn (): PaymentAccount => PaymentAccount::factory()->create());
        $receipt = $this->receiptFor($approved, attributes: [
            'payment_method' => PaymentMethod::PagoMovil,
            'payment_account_id' => $account->getKey(),
            'paid_at' => '2026-09-10',
            'declared_amount' => '3187.50',
            'declared_currency' => Currency::VES,
        ]);

        $viaApproval = $this->within(fn () => app(ApproveReceiptAction::class)->handle($receipt, new ApproveReceiptData('3187.50', Currency::VES, null, null), $this->staff))->payment;
        $viaManual = $this->register($manual, '3187.50', Currency::VES, method: PaymentMethod::PagoMovil, account: $account, paidAt: '2026-09-10');

        $ignored = ['id', 'layaway_id', 'receipt_id', 'registered_by', 'idempotency_key', 'note', 'created_at', 'updated_at'];
        $this->assertSame(
            array_diff_key((array) DB::table('payments')->where('id', $viaApproval->getKey())->first(), array_flip($ignored)),
            array_diff_key((array) DB::table('payments')->where('id', $viaManual->getKey())->first(), array_flip($ignored)),
        );
        $this->assertSame($this->allocationsOf($viaApproval), $this->allocationsOf($viaManual));
        $this->assertSame($this->installmentsOf($approved), $this->installmentsOf($manual));
        $this->assertSame($this->balanceOf($approved), $this->balanceOf($manual));
        $this->assertSame(7_500, $viaManual->amount_cents_usd->cents);
    }

    public function test_a_foreign_account_or_actor_is_refused(): void
    {
        $layaway = $this->activeLayaway();
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): PaymentAccount => PaymentAccount::factory()->create());
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->assertInstanceOf(CrossTenantRelationException::class, $this->refused(fn () => $this->register($layaway, '30.00', Currency::USD, account: $foreign)));
        $this->assertInstanceOf(AuthorizationException::class, $this->refused(fn () => $this->within(fn () => app(RegisterManualPaymentAction::class)->handle(
            $layaway, $this->data('30.00', Currency::USD), $intruder,
        ))));
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_the_same_refusals_as_an_approval(): void
    {
        $layaway = $this->activeLayaway();
        $completed = $this->layawayIn($this->organization, LayawayStatus::Completed);

        $this->assertInstanceOf(PaymentNotApplicableException::class, $this->refused(fn () => $this->register($completed, '30.00', Currency::USD)));
        $this->assertInstanceOf(UnconfirmedExcessException::class, $this->refused(fn () => $this->register($layaway, '160.00', Currency::USD, confirmed: 500)));
        $this->assertInstanceOf(UnconfirmedExcessException::class, $this->refused(fn () => $this->register($layaway, '160.00', Currency::USD, confirmed: 2_000)));

        DB::table('exchange_rates')->delete();
        Cache::flush();
        $this->assertInstanceOf(MissingExchangeRateException::class, $this->refused(fn () => $this->register($layaway, '1275.00', Currency::VES)));

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1_000, $this->register($layaway, '160.00', Currency::USD, confirmed: 1_000)->unallocated_cents->cents);
    }

    public function test_it_works_with_the_organization_suspended(): void
    {
        $layaway = $this->activeLayaway();
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->assertSame(3_000, $this->register($layaway, '30.00', Currency::USD)->amount_cents_usd->cents);
    }

    private function register(
        Layaway $layaway,
        string $amount,
        Currency $currency,
        PaymentMethod $method = PaymentMethod::EfectivoUsd,
        ?PaymentAccount $account = null,
        string $paidAt = '2026-09-10',
        ?string $key = null,
        ?int $confirmed = null,
        ?string $rate = null,
    ): Payment {
        return $this->within(fn (): Payment => app(RegisterManualPaymentAction::class)->handle(
            $layaway,
            $this->data($amount, $currency, $method, $account, $paidAt, $key, $confirmed, $rate),
            $this->staff,
        ));
    }

    private function data(
        string $amount,
        Currency $currency,
        PaymentMethod $method = PaymentMethod::EfectivoUsd,
        ?PaymentAccount $account = null,
        string $paidAt = '2026-09-10',
        ?string $key = null,
        ?int $confirmed = null,
        ?string $rate = null,
    ): ManualPaymentData {
        return new ManualPaymentData($method, $account, $amount, $currency, $rate, CarbonImmutable::parse($paidAt), self::NOTE, $key ?? (string) Str::uuid(), $confirmed);
    }

    private function stored(Payment $payment): Payment
    {
        return $this->within(fn (): Payment => Payment::query()->findOrFail($payment->getKey()));
    }

    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->register($layaway, '50.00', Currency::USD);

        return $layaway;
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
     * @return list<array{int, int, string, string|null}>
     */
    private function installmentsOf(Layaway $layaway): array
    {
        return DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['number', 'paid_cents', 'status', 'paid_at'])
            ->map(fn (object $row): array => [(int) $row->number, (int) $row->paid_cents, (string) $row->status, $row->paid_at])->all();
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
