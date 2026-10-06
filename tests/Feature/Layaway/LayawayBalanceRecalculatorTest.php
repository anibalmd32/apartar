<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Layaway\BalanceDiscrepancy;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayCompleted;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El balance materializado de `REQ-PAY-008` (`T-057`).
 *
 * Los apartados son de verdad: se siembran en `pending_down_payment` y se
 * activan, con el reloj en el 10/09 —cinco cuotas de $30 que vencen el 25/09,
 * 10/10, 25/10, 09/11 y 24/11, y $50 de inicial—. El negocio está en
 * `Asia/Tokyo` y no en la zona de la aplicación, para que una fecha tomada de
 * la zona equivocada no pase por casualidad.
 *
 * **Los pagos se aplican con `PaymentApplier`** (`pay()`), el único que los
 * crea desde `T-058`: activar con la inicial, repartir el resto con
 * `FifoAllocator`, el pago con su sobrante, el estado de cada cuota y
 * recalcular. Lo que el FIFO no puede producir —una cuota cubierta saltándose
 * las anteriores— lo siembra `cover()`, como lo dejaría una reasignación.
 */
final class LayawayBalanceRecalculatorTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private const TIMEZONE = 'Asia/Tokyo';

    private Organization $organization;

    private ?User $staff = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['timezone' => self::TIMEZONE]);
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * El gate de `REQ-PAY-008`: las cuatro invariantes de RB-3 tras **cada**
     * pago de una secuencia de diez, en doce secuencias con semilla fija.
     *
     * Los importes salen de cuatro tramos, cada uno por lo que puede romper:
     * pagos de pocos centavos (muchas cuotas a medias), el borde exacto de lo
     * que falta en la cuota en curso ±1 centavo (cubrir justo, quedarse a uno,
     * pasarse a la siguiente), pagos de varias cuotas (la cascada) y uno que
     * excede todo el saldo (el sobrante). La primera es siempre la inicial más
     * algo de excedente, que reparte sobre el cronograma recién creado.
     */
    public function test_the_four_invariants_hold_after_each_of_ten_random_payments(): void
    {
        mt_srand(20_261_005);

        $completed = 0;

        for ($sequence = 0; $sequence < 12; $sequence++) {
            $layaway = $this->layawayIn($this->organization);

            $this->pay($layaway, 5_000 + mt_rand(0, 4_000));
            $this->assertInvariants($layaway, "secuencia {$sequence}, pago 1");

            for ($payment = 2; $payment <= 10; $payment++) {
                if (! $this->fresh($layaway)->status->acceptsPayments()) {
                    $completed++;

                    break;
                }

                $this->pay($layaway, $this->randomAmount($layaway));
                $this->assertInvariants($layaway, "secuencia {$sequence}, pago {$payment}");
            }
        }

        // Los tramos tienen que llegar a completar alguno, o la tolerancia y
        // el sobrante no se habrían probado.
        $this->assertGreaterThan(0, $completed);
    }

    /**
     * RB-3 · `next_due_date` es el **menor** vencimiento sin cubrir, no «el
     * siguiente al último pagado». Se cubre la cuota intermedia sola —como lo
     * dejaría una reasignación (`REQ-PAY-009`)— y el vencimiento no se mueve
     * de la primera; luego se cubre la primera y salta a la segunda; luego la
     * segunda, y salta la tercera, ya cubierta, hasta la cuarta.
     */
    public function test_next_due_date_is_the_earliest_uncovered_installment_after_paying_the_middle_one(): void
    {
        $layaway = $this->paidDownPayment();
        $this->assertSame('2026-09-25', $this->fresh($layaway)->next_due_date?->toDateString());

        $this->cover($layaway, 3);
        $this->assertSame('2026-09-25', $this->fresh($layaway)->next_due_date?->toDateString());

        $this->cover($layaway, 1);
        $this->assertSame('2026-10-10', $this->fresh($layaway)->next_due_date?->toDateString());

        $this->cover($layaway, 2);
        $this->assertSame('2026-11-09', $this->fresh($layaway)->next_due_date?->toDateString());
        $this->assertSame(5_000 + 9_000, $this->fresh($layaway)->paid_cents->cents);
    }

    /**
     * El *happy path* de RB-3: pagar dos cuotas en cascada mueve `paid_cents`
     * y `remaining_cents` lo mismo y lleva el vencimiento a la tercera.
     */
    public function test_a_payment_moves_paid_and_remaining_by_the_same_amount_and_advances_the_due_date(): void
    {
        $layaway = $this->paidDownPayment();

        $this->pay($layaway, 6_000);

        $row = $this->fresh($layaway);
        $this->assertSame(11_000, $row->paid_cents->cents);
        $this->assertSame(9_000, $row->remaining_cents->cents);
        $this->assertSame('2026-10-25', $row->next_due_date?->toDateString());
    }

    /**
     * SP-2 · un exceso mal conciliado —cuotas con más pagado que su importe—
     * no deja `remaining_cents` negativo: se acota a cero, se registra la
     * anomalía con lo que sobra, y `diff()` la sigue reportando.
     */
    public function test_remaining_is_clamped_to_zero_and_the_excess_is_reported(): void
    {
        $layaway = $this->paidDownPayment();
        DB::table('installments')->where('layaway_id', $layaway->getKey())->update(['paid_cents' => 3_000, 'status' => 'paid']);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 5)->update(['paid_cents' => 3_100]);

        $logger = Log::spy();

        $this->recalculate($layaway);

        $row = $this->fresh($layaway);
        $this->assertSame(0, $row->remaining_cents->cents);
        $this->assertSame(20_100, $row->paid_cents->cents);

        $logger->shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'layaway_balance.overpaid'
                && $context['layaway_id'] === $layaway->getKey()
                && $context['overpaid_cents'] === 100,
        );

        $discrepancy = $this->diff($layaway);
        $this->assertInstanceOf(BalanceDiscrepancy::class, $discrepancy);
        $this->assertSame(100, $discrepancy->overpaidCents);
        $this->assertSame([], $discrepancy->fields);
    }

    /**
     * `REQ-PAY-005` RB-7 en su borde: quedar a la tolerancia exacta completa
     * —por la máquina: marca, cupo liberado—, y quedar un centavo por encima
     * no.
     */
    public function test_a_remaining_within_the_tolerance_completes_through_the_state_machine(): void
    {
        $this->setTolerance(50);

        $above = $this->paidDownPayment();
        $this->pay($above, 15_000 - 51);
        $this->assertSame(LayawayStatus::Active, $this->fresh($above)->status);
        $this->assertSame(51, $this->fresh($above)->remaining_cents->cents);

        $within = $this->paidDownPayment();
        $countedBefore = $this->activeLayawaysCounter();
        $this->pay($within, 15_000 - 50);

        $row = $this->fresh($within);
        $this->assertSame(LayawayStatus::Completed, $row->status);
        $this->assertNotNull($row->completed_at);
        $this->assertSame(50, $row->remaining_cents->cents);
        $this->assertSame($countedBefore - 1, $this->activeLayawaysCounter());
    }

    /**
     * La tolerancia es la del negocio, no la de por defecto: con cero, un
     * centavo pendiente no completa.
     */
    public function test_the_tolerance_is_the_organizations_own(): void
    {
        $this->setTolerance(0);

        $layaway = $this->paidDownPayment();
        $this->pay($layaway, 15_000 - 1);
        $this->assertSame(LayawayStatus::Active, $this->fresh($layaway)->status);

        $this->pay($layaway, 1);
        $this->assertSame(LayawayStatus::Completed, $this->fresh($layaway)->status);
    }

    /**
     * `REQ-LAY-007` RB-6 · cubrir lo vencido devuelve el apartado a `active`;
     * un abono que deja la cuota a medias no lo saca de la mora (RB-4).
     */
    public function test_covering_the_overdue_installment_returns_the_layaway_to_active(): void
    {
        $layaway = $this->paidDownPayment();

        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        $this->within(fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::now()));
        $this->assertSame(LayawayStatus::Overdue, $this->fresh($layaway)->status);

        $this->pay($layaway, 1_000);
        $this->assertSame(LayawayStatus::Overdue, $this->fresh($layaway)->status);

        $this->pay($layaway, 2_000);
        $this->assertSame(LayawayStatus::Active, $this->fresh($layaway)->status);
        $this->assertSame('2026-10-10', $this->fresh($layaway)->next_due_date?->toDateString());
    }

    /**
     * `REQ-LAY-005` SP-5 y `REQ-PAY-005` RB-7 · saldar desde la mora completa:
     * la tolerancia gana a la mora, aunque quede un resto sobre una cuota
     * vencida. Y la máquina lo registra como salida de `overdue`.
     */
    public function test_settling_an_overdue_layaway_within_the_tolerance_completes_it(): void
    {
        $this->setTolerance(50);
        $layaway = $this->paidDownPayment();

        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        $this->within(fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::now()));
        $this->assertSame(LayawayStatus::Overdue, $this->fresh($layaway)->status);
        $countedBefore = $this->activeLayawaysCounter();

        Event::fake([LayawayCompleted::class]);
        $this->pay($layaway, 15_000 - 50);

        $row = $this->fresh($layaway);
        $this->assertSame(LayawayStatus::Completed, $row->status);
        $this->assertNotNull($row->completed_at);
        $this->assertSame($countedBefore - 1, $this->activeLayawaysCounter());
        Event::assertDispatched(LayawayCompleted::class, fn (LayawayCompleted $event): bool => $event->from === LayawayStatus::Overdue);
    }

    /**
     * Y al revés, el apartado sigue a sus cuotas (`REQ-LAY-007` RB-2): si una
     * queda en mora —una reasignación que descubre una cuota vencida,
     * `REQ-PAY-009` SP-4—, entra en `overdue`.
     */
    public function test_an_overdue_installment_takes_an_active_layaway_into_overdue(): void
    {
        $layaway = $this->paidDownPayment();
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['status' => 'overdue']);

        $this->recalculate($layaway);

        $this->assertSame(LayawayStatus::Overdue, $this->fresh($layaway)->status);
    }

    /**
     * Sin activar no hay inicial aplicada ni transición: el recalculador no
     * activa (eso es de `ActivateLayawayAction`). Y un terminal no se mueve,
     * aunque su balance se escriba.
     */
    public function test_pending_and_terminal_layaways_keep_their_status(): void
    {
        $pending = $this->layawayIn($this->organization);
        $this->recalculate($pending);
        $row = $this->fresh($pending);
        $this->assertSame(LayawayStatus::PendingDownPayment, $row->status);
        $this->assertSame(0, $row->paid_cents->cents);
        $this->assertSame(20_000, $row->remaining_cents->cents);
        $this->assertNull($row->next_due_date);

        $cancelled = $this->paidDownPayment();
        $this->within(fn () => app(LayawayStateMachine::class)->transition($cancelled, LayawayStatus::Cancelled));
        $this->recalculate($cancelled);
        $this->assertSame(LayawayStatus::Cancelled, $this->fresh($cancelled)->status);
        $this->assertSame(5_000, $this->fresh($cancelled)->paid_cents->cents);
    }

    /**
     * Recalcular un apartado que cuadra no toca la fila: no hay `UPDATE`, ni
     * siquiera de `updated_at`.
     */
    public function test_recalculating_a_balanced_layaway_does_not_write(): void
    {
        $layaway = $this->paidDownPayment();

        DB::enableQueryLog();
        $this->recalculate($layaway);
        $updates = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'));
        DB::disableQueryLog();

        $this->assertSame([], array_values($updates));
    }

    /**
     * El modelo que se pasa sale con lo que quedó en la fila, como con la
     * máquina de estados.
     */
    public function test_the_given_model_leaves_with_the_new_balance(): void
    {
        $layaway = $this->paidDownPayment();
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);

        $this->recalculate($layaway);

        $this->assertSame(8_000, $layaway->paid_cents->cents);
        $this->assertSame('2026-10-10', $layaway->next_due_date?->toDateString());
        $this->assertFalse($layaway->isDirty());
    }

    /**
     * `diff()` lee y no escribe: una columna alterada a mano se reporta con lo
     * guardado y lo derivado, sigue alterada después, y `recalculate()` la
     * arregla.
     */
    public function test_diff_reports_a_stale_column_without_writing_and_recalculate_fixes_it(): void
    {
        $layaway = $this->paidDownPayment();
        $this->assertNull($this->diff($layaway));

        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 1, 'next_due_date' => '2026-12-31']);

        $discrepancy = $this->diff($layaway);
        $this->assertInstanceOf(BalanceDiscrepancy::class, $discrepancy);
        $this->assertSame([
            'paid_cents' => ['stored' => 1, 'derived' => 5_000],
            'next_due_date' => ['stored' => '2026-12-31', 'derived' => '2026-09-25'],
        ], $discrepancy->fields);
        $this->assertTrue($discrepancy->isFixedByRecalculating());
        $this->assertSame(1, (int) DB::table('layaways')->where('id', $layaway->getKey())->value('paid_cents'));

        $this->recalculate($layaway);
        $this->assertNull($this->diff($layaway));
    }

    /**
     * La primera invariante contra la segunda: un pago cuyas asignaciones se
     * perdieron cuenta en los pagos y no en las cuotas. Recalcular no lo
     * arregla —el balance sale de las cuotas— y `diff()` lo dice.
     */
    public function test_diff_reports_payments_that_the_schedule_does_not_explain(): void
    {
        $layaway = $this->paidDownPayment();
        $this->within(fn () => Payment::factory()->createOne([
            'layaway_id' => $layaway->getKey(),
            'amount_cents_usd' => new Money(3_000),
            'unallocated_cents' => new Money(0),
        ]));

        $discrepancy = $this->diff($layaway);

        $this->assertInstanceOf(BalanceDiscrepancy::class, $discrepancy);
        $this->assertTrue($discrepancy->ledgerMismatch());
        $this->assertSame(8_000, $discrepancy->paidFromPayments);
        $this->assertSame(5_000, $discrepancy->paidFromSchedule);
        $this->assertFalse($discrepancy->isFixedByRecalculating());
    }

    /**
     * La transición que el recalculador pediría también es una discrepancia,
     * y `diff()` no la pide.
     */
    public function test_diff_reports_the_transition_without_asking_for_it(): void
    {
        $layaway = $this->paidDownPayment();
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['status' => 'overdue']);

        $discrepancy = $this->diff($layaway);

        $this->assertInstanceOf(BalanceDiscrepancy::class, $discrepancy);
        $this->assertSame(['status' => ['stored' => 'active', 'derived' => 'overdue']], $discrepancy->fields);
        $this->assertSame(LayawayStatus::Active, $this->fresh($layaway)->status);
    }

    /**
     * RB-7, la capa del modelo: guardar el balance de un apartado existente
     * lanza, columna por columna.
     */
    public function test_the_model_refuses_to_save_a_balance_column(): void
    {
        $layaway = $this->paidDownPayment();
        $changes = ['paid_cents' => new Money(1), 'remaining_cents' => new Money(1), 'next_due_date' => '2026-12-31'];

        $this->assertEqualsCanonicalizing(Layaway::BALANCE_COLUMNS, array_keys($changes), 'El caso tiene que intentar cada columna del balance.');

        foreach ($changes as $column => $value) {
            try {
                $this->within(fn () => Layaway::query()->findOrFail($layaway->getKey())->forceFill([$column => $value])->save());
                $this->fail("Se guardó {$column} de un apartado fuera del recalculador.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($column, $exception->getMessage());
            }
        }

        $this->assertNull($this->diff($layaway));
    }

    /**
     * Y al crear: un apartado solo nace sin pagos. Es la regla que admite a
     * `CreateLayawayAction` y a los sembradores, y la que rechaza a cualquier
     * otro que quiera nacer con saldo.
     *
     * @param  array<string, mixed>  $balance
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bornBalances')]
    public function test_a_layaway_is_born_without_payments(array $balance): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nace sin pagos');

        $this->within(fn () => Layaway::factory()->createOne($balance));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function bornBalances(): array
    {
        return [
            // Cada caso rompe una sola de las tres condiciones: el saldo de
            // este es el total, y lo que lo rechaza es lo pagado.
            'con algo pagado' => [['paid_cents' => new Money(5_000)]],
            'con un saldo que no es el total' => [['remaining_cents' => new Money(19_999)]],
            'con vencimiento' => [['next_due_date' => '2026-09-25']],
        ];
    }

    /**
     * Las cuatro invariantes de RB-3, leídas de la base, más dos que las
     * sostienen: el reparto de cada cuota suma su `paid_cents`, y `diff()` no
     * encuentra nada.
     */
    private function assertInvariants(Layaway $layaway, string $where): void
    {
        $this->within(function () use ($layaway, $where): void {
            $row = $this->fresh($layaway);
            $payments = Payment::query()->where('layaway_id', $row->getKey())->get();
            $installments = Installment::query()->where('layaway_id', $row->getKey())->orderBy('number')->get();
            $downPayment = $row->activated_at === null ? 0 : $row->terms()->totalDownPayment()->cents;

            $fromPayments = $payments->sum(fn (Payment $payment): int => $payment->amount_cents_usd->cents - $payment->unallocated_cents->cents);
            $this->assertSame($fromPayments, $row->paid_cents->cents, "{$where}: paid_cents === sum(payments) − sum(unallocated)");

            $fromSchedule = $installments->sum(fn (Installment $installment): int => $installment->paid_cents->cents) + $downPayment;
            $this->assertSame($fromSchedule, $row->paid_cents->cents, "{$where}: paid_cents === sum(installments.paid_cents) + inicial");

            $this->assertSame($row->total_cents->cents - $row->paid_cents->cents, $row->remaining_cents->cents, "{$where}: remaining === total − paid");
            $this->assertGreaterThanOrEqual(0, $row->remaining_cents->cents, "{$where}: remaining nunca negativo");

            $unpaid = $installments->filter(fn (Installment $installment): bool => $installment->status !== InstallmentStatus::Paid);
            $this->assertSame($unpaid->min(fn (Installment $installment): string => $installment->due_date->toDateString()), $row->next_due_date?->toDateString(), "{$where}: next_due_date");

            foreach ($installments as $installment) {
                $this->assertSame($installment->paid_cents->cents, (int) $installment->allocations()->sum('amount_cents'), "{$where}: reparto de la cuota {$installment->number}");
            }

            $tolerance = 50;
            $expected = $row->remaining_cents->cents <= $tolerance ? LayawayStatus::Completed : LayawayStatus::Active;
            $this->assertSame($expected, $row->status, "{$where}: estado");

            $this->assertNull(app(LayawayBalanceRecalculator::class)->diff($row), "{$where}: diff()");
        });
    }

    /**
     * Un importe de uno de los cuatro tramos, sobre lo que le falta ahora al
     * apartado.
     */
    private function randomAmount(Layaway $layaway): int
    {
        $row = $this->fresh($layaway);
        $current = $this->within(fn (): ?Installment => Installment::query()
            ->where('layaway_id', $row->getKey())
            ->whereColumn('paid_cents', '<', 'amount_cents')
            ->orderBy('number')
            ->first());
        $missing = $current === null ? 1 : $current->amount_cents->cents - $current->paid_cents->cents;

        return match (mt_rand(1, 10)) {
            1, 2, 3 => mt_rand(1, 300),
            4, 5, 6 => max(1, $missing + mt_rand(-1, 1)),
            7, 8, 9 => mt_rand(3_000, 7_000),
            10 => $row->remaining_cents->cents + mt_rand(1, 5_000),
        };
    }

    /**
     * Un pago en dólares, aplicado por `PaymentApplier`.
     */
    private function pay(Layaway $layaway, int $cents): void
    {
        $this->within(function () use ($layaway, $cents): void {
            $this->staff ??= User::factory()->create();

            app(PaymentApplier::class)->apply($layaway, new Money($cents), null, number_format($cents / 100, 2, '.', ''), Currency::USD, null, $this->staff, null);
        });
    }

    /**
     * Cubre entera una cuota concreta, saltándose el FIFO: lo que dejaría una
     * reasignación (`REQ-PAY-009`).
     */
    private function cover(Layaway $layaway, int $number): void
    {
        $this->within(function () use ($layaway, $number): void {
            $installment = Installment::query()->where('layaway_id', $layaway->getKey())->where('number', $number)->firstOrFail();
            $payment = Payment::factory()->createOne(['layaway_id' => $layaway->getKey(), 'amount_cents_usd' => $installment->amount_cents, 'unallocated_cents' => new Money(0)]);
            PaymentAllocation::factory()->createOne(['payment_id' => $payment->getKey(), 'installment_id' => $installment->getKey(), 'amount_cents' => $installment->amount_cents]);
            $installment->forceFill(['paid_cents' => $installment->amount_cents, 'status' => InstallmentStatus::Paid])->save();

            app(LayawayBalanceRecalculator::class)->recalculate($layaway);
        });
    }

    /**
     * Un apartado activado con su inicial pagada, y nada más.
     */
    private function paidDownPayment(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->pay($layaway, 5_000);

        return $layaway;
    }

    private function recalculate(Layaway $layaway): void
    {
        $this->within(fn () => app(LayawayBalanceRecalculator::class)->recalculate($layaway));
    }

    private function diff(Layaway $layaway): ?BalanceDiscrepancy
    {
        return $this->within(fn (): ?BalanceDiscrepancy => app(LayawayBalanceRecalculator::class)->diff($layaway));
    }

    private function fresh(Layaway $layaway): Layaway
    {
        return $this->within(fn (): Layaway => Layaway::query()->findOrFail($layaway->getKey()));
    }

    private function setTolerance(int $cents): void
    {
        $this->within(function () use ($cents): void {
            OrganizationSetting::query()->where('key', SettingKey::SettlementToleranceCents)->delete();
            OrganizationSetting::factory()->forKey(SettingKey::SettlementToleranceCents, $cents)->create();
            app(Settings::class)->forget($this->organization);
        });
    }

    private function activeLayawaysCounter(): int
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
