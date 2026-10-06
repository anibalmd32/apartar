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
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\UsageMetric;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * `PaymentApplier` (`T-058`, `REQ-PAY-005`, `REQ-PAY-007`): el colaborador
 * que crea pagos.
 *
 * Los apartados son de verdad —$200 con $50 de inicial en cinco cuotas de $30
 * que, activados el 10/09, vencen el 25/09, 10/10, 25/10, 09/11 y 24/11—, y el
 * negocio está en `Asia/Tokyo`, nueve horas por delante de la zona de la
 * aplicación, para que el «hoy» de la mora se mida donde toca.
 */
final class PaymentApplierTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Tokyo']);
        $this->staff = $this->within(fn (): User => User::factory()->create());
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');

        // Una fuente configurada distinta de la de los snapshots del test,
        // para que la que se congela diga de dónde salió.
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

    /**
     * `REQ-FX-006` RB-4: un pago en dólares no convierte —tasa 1— y guarda la
     * fuente vigente con su fecha de publicación.
     */
    public function test_a_dollar_payment_freezes_rate_one_with_the_current_source(): void
    {
        $applied = $this->apply($this->activeLayaway(), new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD);

        $payment = $this->stored($applied);
        $this->assertSame(3_000, $payment->amount_cents_usd->cents);
        $this->assertSame('1.00000000', $payment->rate_applied);
        // La del snapshot, no la configurada (`parallel`, en `setUp()`).
        $this->assertSame(RateSource::Bcv, $payment->rate_source);
        $this->assertSame('2026-09-09 13:00:00', $payment->rate_published_at?->toDateTimeString());
        $this->assertSame(Currency::USD, $payment->declared_currency);
        $this->assertSame('30.00', $payment->declared_amount);
        $this->assertSame($this->staff->getKey(), $payment->registered_by);
        $this->assertSame('Recibido en tienda.', $payment->note);
        $this->assertSame('2026-09-10 10:00:00', $payment->settled_at->toDateTimeString());
    }

    /**
     * `REQ-FX-006` RB-3: la tasa con la que se convierte es la que se congela.
     * Una manual configurada con nueve decimales se lleva a la escala de
     * `rate_applied` **antes** de convertir, en vez de convertir con nueve y
     * dejar que MySQL guarde otra.
     */
    public function test_the_rate_is_brought_to_the_column_scale_before_converting(): void
    {
        $configured = new RateSnapshot('36.123456789', RateSource::Manual, CarbonImmutable::parse('2026-09-08 09:00:00'), CarbonImmutable::parse('2026-09-08 09:00:00'));

        $applied = $this->apply($this->activeLayaway(), new Money(361_235, Currency::VES), $configured, '3612.35', Currency::VES);

        $this->assertSame('36.12345679', $applied->payment->getAttributes()['rate_applied']);
        $this->assertSame('36.12345679', $this->stored($applied)->rate_applied);
        $this->assertSame(10_000, $this->stored($applied)->amount_cents_usd->cents);
        $this->assertSame('2026-09-08 09:00:00', $this->stored($applied)->rate_published_at?->toDateTimeString());
    }

    /**
     * `REQ-FX-006` RB-2 y el *happy path* de `REQ-PAY-005`: Bs. 2.550 a 42,50
     * son $60, que cubren las cuotas 1 y 2; el pago congela la tasa y lo
     * declarado en bolívares.
     */
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

    /**
     * SP-3 de `REQ-FX-006`: la tasa escrita a mano se congela como `manual` y
     * sin fecha de publicación, porque no la publicó nadie.
     */
    public function test_a_manual_rate_is_frozen_as_manual_without_publication_date(): void
    {
        $applied = $this->apply($this->activeLayaway(), new Money(150_000, Currency::VES), new ManualRate('50'), '1500.00', Currency::VES);

        $payment = $this->stored($applied);
        $this->assertSame(3_000, $payment->amount_cents_usd->cents);
        $this->assertSame('50.00000000', $payment->rate_applied);
        $this->assertSame(RateSource::Manual, $payment->rate_source);
        $this->assertNull($payment->rate_published_at);
    }

    /**
     * `REQ-FX-006` SP-4: Bs. 0,01 a 42,50 no llega a un centavo. No se crea
     * nada.
     */
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

    /**
     * `REQ-PAY-005` RB-10, SP-6: en bolívares hace falta tasa; en dólares no,
     * y se guarda la fuente que el negocio tiene configurada, sin fecha.
     */
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

    /**
     * `REQ-PAY-005` RB-5: la inicial activa —cronograma, inicial aplicada— y
     * el excedente sobre ella va a las primeras cuotas. `AppliedPayment` dice
     * en qué se fue cada centavo.
     */
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

    /**
     * Justo la inicial: activa y no reparte nada —`FifoAllocator` no se llama
     * con cero—.
     */
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

    /**
     * Un pago que no cubre la inicial no activa: el apartado sigue esperando,
     * sin cronograma y sin pago.
     */
    public function test_less_than_the_down_payment_is_refused(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->expectException(PaymentNotApplicableException::class);

        try {
            $this->apply($layaway, new Money(4_999), $this->bcv('42.50'), '49.99', Currency::USD);
        } finally {
            $this->assertSame(LayawayStatus::PendingDownPayment, $this->fresh($layaway)->status);
            $this->assertSame(0, $this->paymentsOf($layaway));
            $this->assertSame(0, $this->within(fn (): int => Installment::query()->where('layaway_id', $layaway->getKey())->count()));
        }
    }

    /**
     * `REQ-PAY-005` SP-9: financiado cero —inicial igual al total— completa al
     * aprobar la inicial, y lo que sobre queda sin asignar.
     */
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

    /**
     * `REQ-PAY-005` RB-6, RB-7, SP-5: un pago que excede el saldo cubre todo,
     * guarda el resto como sobrante —sin crédito—, completa y libera el cupo.
     */
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

    /**
     * El estado de cada cuota es la regla de la mora, con el «hoy» **del
     * negocio**. El 28/09 a mediodía en la zona de la aplicación ya es el 29
     * en Tokio: la cuota del 25/09 pasó su gracia de tres días, así que un
     * abono a medias la deja `overdue` (`REQ-LAY-007` RB-4) —con el «hoy» de
     * Caracas quedaría `partially_paid`— y el apartado la sigue. Cubrirla lo
     * devuelve a `active` (RB-6).
     */
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

    /**
     * `REQ-PAY-005` RB-8: cubrir la cuota vencida **no** saca de la mora si
     * otra pasó su gracia y la pasada de las 06:00 todavía no la marcó —corrió
     * por última vez el 29/09—. El pago mira el estado de todas las cuotas con
     * el «hoy» del negocio, no solo el de las que cubre, y no emite un «al día»
     * que la pasada siguiente tendría que desmentir.
     */
    public function test_covering_one_overdue_installment_does_not_hide_another_one_past_grace(): void
    {
        $layaway = $this->activeLayaway();
        $this->within(fn () => app(\App\Actions\Layaway\DetectOverdueAction::class)->handle(CarbonImmutable::parse('2026-09-29 12:00:00')));
        $this->assertSame([[1, 0, 'overdue'], [2, 0, 'pending']], $this->installments($layaway, 2));

        // El 14/10 a las 03:00 en Tokio: la cuota del 10/10 pasó su gracia.
        CarbonImmutable::setTestNow('2026-10-13 14:00:00');
        Event::fake([LayawayCaughtUp::class]);

        $applied = $this->apply($layaway, new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD);

        $this->assertSame([[1, 3_000, 'paid'], [2, 0, 'overdue']], $this->installments($layaway, 2));
        $this->assertSame(LayawayStatus::Overdue, $applied->statusAfter);
        Event::assertNotDispatched(LayawayCaughtUp::class);
    }

    /**
     * `REQ-PAY-005` SP-2: un apartado terminal no cobra, y no se escribe nada.
     */
    public function test_a_terminal_layaway_is_refused(): void
    {
        $layaway = $this->activeLayaway();
        $this->within(fn () => app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Cancelled));

        $this->expectException(PaymentNotApplicableException::class);
        $this->expectExceptionMessage('Cancelado');

        $this->apply($layaway, new Money(3_000), $this->bcv('42.50'), '30.00', Currency::USD);
    }

    /**
     * `REQ-PAY-007` SP-5: la clave va en el `INSERT`, y la segunda vez choca
     * en la base y sube a quien llamó, sin dejar nada a medias.
     */
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

    /**
     * `Payment` es inmutable: se escribe entero en el `INSERT`, sin un
     * `UPDATE` después.
     */
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

    /**
     * El pago de un comprobante lo enlaza; uno manual exige quién lo registró
     * (`REQ-PAY-007`), y un comprobante de otro apartado no se aplica aquí.
     */
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

    /**
     * `REQ-PAY-005` SP-8: el aplicador escribe en la transacción del llamador,
     * y si algo falla después se deshace todo: el apartado sigue esperando su
     * inicial, sin cronograma ni pago.
     */
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

    /**
     * Las cifras de un importe en cero o un declarado inválido no llegan a
     * bloquear nada.
     */
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

    /**
     * Activado con su inicial, por el propio aplicador.
     */
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
     * Número, pagado y estado de las primeras `$count` cuotas.
     *
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
