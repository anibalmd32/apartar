<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Layaway\ExpireLayawaysAction;
use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Actions\Payment\VoidPaymentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\DownPaymentLedger;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentNotVoidableException;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayReopened;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class VoidPaymentActionTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    private const REASON = 'El comprobante era de otro cliente.';

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->staff = $this->within(fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_anula_el_pago_deshace_sus_cuotas_y_lo_deja_en_el_historial(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '45.00');
        $this->assertSame([[1, 3_000, 'paid'], [2, 1_500, 'partially_paid'], [3, 0, 'pending']], array_slice($this->installmentsOf($layaway), 0, 3));
        CarbonImmutable::setTestNow('2026-09-12 16:00:00');

        $voided = $this->void($payment);

        $this->assertTrue($voided->isVoided());
        $this->assertSame([[1, 0, 'pending'], [2, 0, 'pending'], [3, 0, 'pending']], array_slice($this->installmentsOf($layaway), 0, 3));
        $this->assertSame([null, null], DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->limit(2)->pluck('paid_at')->all());
        $this->assertSame(0, DB::table('payment_allocations')->where('payment_id', $payment->getKey())->count());
        $this->assertSame(['2026-09-12 16:00:00', $this->staff->getKey(), self::REASON], array_values((array) DB::table('payments')->where('id', $payment->getKey())->first(['voided_at', 'voided_by', 'void_reason'])));
        $this->assertSame(['active', 5_000, 15_000, '2026-09-25'], array_values($this->balanceOf($layaway)));
        $this->assertNull($this->within(fn () => app(LayawayBalanceRecalculator::class)->diff($layaway)));
        $this->assertSame(1, $this->within(fn (): int => Payment::query()->where('layaway_id', $layaway->getKey())->count()));
        $this->assertSame(2, $this->within(fn (): int => Payment::query()->withVoided()->where('layaway_id', $layaway->getKey())->count()));

        $changed = $this->within(fn (): array => AuditEntry::query()->where('event', AuditEvent::PaymentVoided->value)->sole()->properties?->toArray()['changed'] ?? []);
        $this->assertSame(4_500, $changed['amount_cents_usd']['before'] ?? null);
        $this->assertSame([['cents' => 3_000, 'installment' => 1], ['cents' => 1_500, 'installment' => 2]], $changed['allocations']['before'] ?? null);
        $this->assertSame([9_500, 5_000], [$changed['paid_cents']['before'] ?? null, $changed['paid_cents']['after'] ?? null]);
        $this->assertSame(self::REASON, $changed['reason']['after'] ?? null);
    }

    public function test_un_pago_anulado_deja_de_contar_en_el_saldo_y_en_la_inicial(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $wrong = $this->pay($layaway, '30.00');
        $this->pay($layaway, '10.00');
        $this->assertSame(4_000, $this->balanceOf($layaway)['paid_cents']);

        $this->void($wrong);

        $this->assertSame(['pending_down_payment', 1_000, 19_000], array_slice(array_values($this->balanceOf($layaway)), 0, 3));
        $this->assertSame(1_000, $this->within(fn (): int => app(DownPaymentLedger::class)->paidOn($layaway)));
        $this->assertNull($this->within(fn () => app(LayawayBalanceRecalculator::class)->diff($layaway)));
        $this->assertSame([], $this->within(fn (): array => app(LayawayBalanceRecalculator::class)->diffMany([Layaway::query()->findOrFail($layaway->getKey())])));

        $this->pay($layaway, '40.00');
        $this->assertSame(['active', 5_000], array_slice(array_values($this->balanceOf($layaway)), 0, 2));
    }

    public function test_un_apartado_sin_mas_pagos_que_el_anulado_puede_expirar(): void
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => '2026-09-12 10:00:00']);
        $this->void($this->pay($layaway, '20.00'));

        $this->within(fn () => app(ExpireLayawaysAction::class)->handle(CarbonImmutable::parse('2026-09-13 10:00:00')));

        $this->assertSame('expired', $this->balanceOf($layaway)['status']);
    }

    public function test_reabre_el_apartado_pagado_que_vuelve_a_deber(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '150.00');
        $this->assertSame('completed', $this->balanceOf($layaway)['status']);
        $slots = $this->slots();
        $dispatched = [];
        Event::listen(LayawayTransitioned::class, function (LayawayTransitioned $event) use (&$dispatched): void {
            $dispatched[] = $event::class;
        });

        $this->void($payment);

        $this->assertSame(['active', 5_000, 15_000, '2026-09-25'], array_values($this->balanceOf($layaway)));
        $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('completed_at'));
        $this->assertSame($slots + 1, $this->slots());
        $this->assertSame([LayawayReopened::class], $dispatched);
        $this->assertSame('completed → active', $this->within(fn (): mixed => AuditEntry::query()->where('event', AuditEvent::PaymentVoided->value)->sole()->properties?->toArray()['changed']['layaway_status']['after'] ?? null));
    }

    public function test_reabre_en_mora_si_alguna_cuota_queda_vencida(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '150.00');
        CarbonImmutable::setTestNow('2026-10-01 10:00:00');

        $this->void($payment);

        $this->assertSame('overdue', $this->balanceOf($layaway)['status']);
        $this->assertSame([1, 0, 'overdue'], $this->installmentsOf($layaway)[0]);
    }

    public function test_no_anula_el_pago_que_cubrio_la_inicial_de_un_apartado_activo(): void
    {
        $layaway = $this->activeLayaway();
        $downPayment = $this->downPaymentOf($layaway);
        $before = [$this->balanceOf($layaway), $this->installmentsOf($layaway)];

        $refused = $this->refused(fn () => $this->void($downPayment));

        $this->assertInstanceOf(PaymentNotVoidableException::class, $refused);
        $this->assertSame('Este pago cubrió la inicial: para anularlo, registra antes el pago correcto de la inicial o cancela el apartado.', $refused->getMessage());
        $this->assertSame($before, [$this->balanceOf($layaway), $this->installmentsOf($layaway)]);
        $this->assertNull(DB::table('payments')->where('id', $downPayment->getKey())->value('voided_at'));
        $this->assertSame(0, $this->within(fn (): int => AuditEntry::query()->where('event', AuditEvent::PaymentVoided->value)->count()));
    }

    public function test_el_comprobante_vuelve_a_revision_y_se_puede_aprobar_otra_vez(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway, attributes: ['declared_amount' => '30.00', 'declared_currency' => Currency::USD]);
        $payment = $this->approve($receipt);
        Notification::fake();

        $this->void($payment);

        $this->assertSame(['pending_review', null, null], array_values((array) DB::table('receipts')->where('id', $receipt->getKey())->first(['status', 'reviewed_by', 'reviewed_at'])));
        $this->assertSame(0, $this->installmentsOf($layaway)[0][1]);
        Notification::assertNothingSent();
        $this->assertSame('pending_review', $this->within(fn (): mixed => AuditEntry::query()->where('event', AuditEvent::PaymentVoided->value)->sole()->properties?->toArray()['changed']['receipt_status']['after'] ?? null));

        $again = $this->approve($this->within(fn (): Receipt => Receipt::query()->findOrFail($receipt->getKey())));

        $this->assertNotSame($payment->getKey(), $again->getKey());
        $this->assertSame(2, DB::table('payments')->where('receipt_id', $receipt->getKey())->count());
        $this->assertSame([1, 3_000, 'paid'], $this->installmentsOf($layaway)[0]);
        $this->assertSame('approved', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    public function test_la_misma_referencia_se_puede_registrar_despues_de_anular(): void
    {
        $layaway = $this->activeLayaway();
        $wrong = $this->pay($layaway, '1275.00', Currency::VES, PaymentMethod::PagoMovil, 'Banesco', '0123456789');

        $this->void($wrong);
        $right = $this->pay($layaway, '1275.00', Currency::VES, PaymentMethod::PagoMovil, 'Banesco', '0123456789');

        $this->assertSame(3_000, $right->amount_cents_usd->cents);
        $this->assertSame([1, 3_000, 'paid'], $this->installmentsOf($layaway)[0]);
    }

    public function test_rechaza_pagos_anulados_motivos_cortos_y_extranos(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '30.00');

        $this->assertInstanceOf(InvalidArgumentException::class, $this->refused(fn () => $this->void($payment, 'Corto')));

        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->create());
        $this->assertInstanceOf(AuthorizationException::class, $this->refused(fn () => $this->within(fn () => app(VoidPaymentAction::class)->handle($payment, $stranger, self::REASON))));

        $this->void($payment);
        $twice = $this->refused(fn () => $this->void($payment));
        $this->assertInstanceOf(PaymentNotVoidableException::class, $twice);
        $this->assertSame('Este pago ya está anulado.', $twice->getMessage());
    }

    public function test_un_apartado_cancelado_deja_anular_su_pago_y_su_inicial_sin_reabrirse(): void
    {
        $layaway = $this->activeLayaway();
        $installment = $this->pay($layaway, '30.00');
        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Cancelled->value]);

        $this->void($installment);
        $this->void($this->downPaymentOf($layaway));

        $this->assertSame('cancelled', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        $this->assertSame(0, (int) DB::table('layaways')->where('id', $layaway->getKey())->value('paid_cents'));
        $this->assertSame(0, $this->installmentsOf($layaway)[0][1]);
    }

    public function test_un_pago_anulado_no_se_desanula(): void
    {
        $voided = $this->void($this->pay($this->activeLayaway(), '30.00'));

        $refused = $this->refused(fn () => $this->within(fn () => $voided->forceFill(['voided_at' => null, 'void_reason' => null])->save()));

        $this->assertInstanceOf(LogicException::class, $refused);
        $this->assertSame('Un pago anulado no se desanula ni cambia su anulación.', $refused->getMessage());
        $this->assertNotNull(DB::table('payments')->where('id', $voided->getKey())->value('voided_at'));
    }

    private function void(Payment $payment, string $reason = self::REASON): Payment
    {
        return $this->within(fn (): Payment => app(VoidPaymentAction::class)->handle($payment, $this->staff, $reason));
    }

    private function approve(Receipt $receipt): Payment
    {
        return $this->within(fn (): Payment => app(ApproveReceiptAction::class)->handle($receipt, new ApproveReceiptData('30.00', Currency::USD, null, null), $this->staff)->payment);
    }

    private function pay(
        Layaway $layaway,
        string $amount,
        Currency $currency = Currency::USD,
        PaymentMethod $method = PaymentMethod::EfectivoUsd,
        ?string $bank = null,
        ?string $reference = null,
    ): Payment {
        return $this->within(fn (): Payment => app(RegisterManualPaymentAction::class)->handle(
            $layaway,
            new ManualPaymentData($method, null, $amount, $currency, null, CarbonImmutable::now(), 'Pago recibido en tienda por Ana.', (string) Str::uuid(), bank: $bank, reference: $reference),
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

    private function slots(): int
    {
        return (int) $this->within(fn (): mixed => UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'));
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
