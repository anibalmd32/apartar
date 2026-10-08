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

/**
 * El negocio aprueba un comprobante (`T-061`, `REQ-PAY-005`).
 *
 * Lo que mueve el dinero ya lo prueba `PaymentApplierTest`; aquí se prueba lo
 * de alrededor y que el camino entero cuadra: el comprobante, la tasa de la
 * organización, el sobrante confirmado, la doble aprobación y el rollback.
 *
 * Apartados de verdad —$200 con $50 de inicial y cinco cuotas de $30 que,
 * activados el 10/09, vencen el 25/09, 10/10, 25/10, 09/11 y 24/11—, con una
 * tasa BCV de 42,50 publicada en la base, y el negocio en `Asia/Tokyo`.
 */
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

    /**
     * RB-4 y el *happy path*: Bs. 2.550 a 42,50 son $60, que cubren las cuotas
     * 1 y 2 en cascada; el comprobante queda aprobado por quien lo aprobó, y
     * la auditoría lleva el reparto.
     */
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

    /**
     * RB-5: aprobar el comprobante de la inicial **activa** el apartado y
     * genera su cronograma; lo que excede la inicial va a la primera cuota.
     */
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

    /**
     * RB-8: cubrir lo vencido devuelve un `overdue` a `active`.
     */
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

    /**
     * RB-7: lo que falta dentro de la tolerancia del negocio completa el
     * apartado y libera su cupo del plan.
     */
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

    /**
     * SP-5: lo que excede el saldo **exige confirmación**. Sin ella, nada se
     * escribe; con ella, se aprueba y el sobrante queda sin asignar.
     */
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

        $applied = $this->approve($receipt, '160.00', Currency::USD, confirmedExcess: 1_000);

        $this->assertSame(1_000, $applied->plan->unallocatedCents);
        $this->assertSame(LayawayStatus::Completed, $applied->statusAfter);

        $after = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReceiptApproved->value)->latest('id')->firstOrFail())->properties?->toArray()['changed'] ?? [];
        $this->assertSame(1_000, $after['unallocated_cents']['after'] ?? null);
        $this->assertSame('160.00 USD', $after['settled']['after'] ?? null);
        $this->assertSame(16_000, $after['amount_cents_usd']['after'] ?? null);
        $this->assertSame('active → completed', $after['layaway_status']['after'] ?? null);
    }

    /**
     * SP-1 bajo el bloqueo: dos aprobaciones que cargaron el comprobante
     * **antes** de que la otra terminara —el mismo «en revisión» en memoria—.
     * La segunda lo relee bloqueado y recibe el nombre, no un `500` del
     * único de `payments`.
     */
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

    /**
     * RB-3 y `REQ-FX-006` RB-4: el pago congela **lo conciliado** —el negocio
     * recibió $20, no los Bs. 850 que declaró el cliente—, y en dólares no
     * convierte: tasa 1 y la fuente vigente. Lo declarado sigue en el
     * comprobante.
     */
    public function test_the_payment_freezes_what_was_settled_and_the_receipt_keeps_what_was_declared(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway(), attributes: ['declared_amount' => '850.00', 'declared_currency' => Currency::VES]);

        $applied = $this->approve($receipt, '20.00', Currency::USD);

        $payment = $this->within(fn (): Payment => Payment::query()->findOrFail($applied->payment->getKey()));
        $this->assertSame(['20.00', Currency::USD, '1.00000000', RateSource::Bcv], [$payment->declared_amount, $payment->declared_currency, $payment->rate_applied, $payment->rate_source]);
        $this->assertSame(['850.00', 'VES'], [(string) DB::table('receipts')->where('id', $receipt->getKey())->value('declared_amount'), DB::table('receipts')->where('id', $receipt->getKey())->value('declared_currency')]);
    }

    /**
     * Una tasa escrita a mano **con un pago en dólares** no se usa: no hay nada
     * que convertir, y el pago queda con la fuente vigente, no como `manual`
     * (`REQ-FX-006` RB-4). Tampoco va a la auditoría como si se hubiera usado.
     */
    public function test_a_hand_written_rate_is_ignored_with_dollars(): void
    {
        $applied = $this->approve($this->receiptFor($this->activeLayaway()), '30.00', Currency::USD, rate: '45.00');

        $payment = $this->within(fn (): Payment => Payment::query()->findOrFail($applied->payment->getKey()));
        $this->assertSame([RateSource::Bcv, '1.00000000'], [$payment->rate_source, $payment->rate_applied]);
        $this->assertNotNull($payment->rate_published_at);

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReceiptApproved->value)->latest('id')->firstOrFail());
        $this->assertArrayNotHasKey('rate_override', $entry->properties?->toArray()['changed'] ?? []);
    }

    /**
     * SP-1 y SP-3: aprobar dos veces falla la segunda, con el nombre de quien
     * lo resolvió, y no crea un segundo pago.
     */
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

    /**
     * SP-8: un fallo **después** de aplicar —al guardar el comprobante— lo
     * deshace todo: sin pago, sin reparto, las cuotas y el balance como
     * estaban y el comprobante en revisión.
     */
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

    /**
     * RB-10: la tasa es la vigente **de la fuente que eligió esa
     * organización** —aquí la paralela, a 50— y se congela con ella.
     */
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

    /**
     * SP-7: una tasa escrita a mano se congela como `manual`, sin fecha de
     * publicación, y queda en la auditoría.
     */
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

    /**
     * SP-6: en bolívares sin ninguna tasa, `422` y nada escrito.
     */
    public function test_bolivars_without_a_rate_are_refused(): void
    {
        $layaway = $this->activeLayaway();
        DB::table('exchange_rates')->delete();
        // La vigente también vive en la caché del servicio (`ExchangeRateService`).
        Cache::flush();
        $receipt = $this->receiptFor($layaway);

        $refused = $this->refused(fn () => $this->approve($receipt, '2550.00', Currency::VES));

        $this->assertInstanceOf(MissingExchangeRateException::class, $refused);
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    /**
     * SP-2: el comprobante de un apartado que ya no cobra no se aprueba.
     */
    public function test_a_receipt_of_a_layaway_that_no_longer_collects_is_refused(): void
    {
        $receipt = $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Completed));

        $this->assertInstanceOf(PaymentNotApplicableException::class, $this->refused(fn () => $this->approve($receipt, '30.00', Currency::USD)));
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    /**
     * RB-2: solo alguien **de esa** organización. El de otra no pasa la
     * policy aunque llegue con el modelo en la mano.
     */
    public function test_someone_of_another_organization_cannot_approve(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->expectException(AuthorizationException::class);

        $this->within(fn () => app(ApproveReceiptAction::class)->handle($receipt, new ApproveReceiptData('30.00', Currency::USD, null, null), $intruder));
    }

    /**
     * RB-12: con la organización suspendida se sigue aprobando.
     */
    public function test_it_works_with_the_organization_suspended(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->assertSame(3_000, $this->approve($receipt, '30.00', Currency::USD)->payment->amount_cents_usd->cents);
    }

    // -------------------------------------------------------------------

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

    /**
     * Activado con su inicial: aprobando el comprobante de la inicial.
     */
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
