<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Actions\Settings\UpdateExchangeRateSettingsAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Jobs\FetchExchangeRate;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * El snapshot de tasa, por los dos caminos que crean pagos (`T-023`,
 * `REQ-FX-006`).
 *
 * La mecánica —congelar la tasa en bolívares, `1` en dólares, la manual sin
 * fecha— la prueba `PaymentApplierTest`, y que el historial no reconvierte,
 * `LayawayHistoryTest`. Aquí, lo que solo se ve con la aprobación **y** el
 * registro manual: que subir la tasa no mueve ningún saldo (RB-7), y que una
 * tasa de más de 30 días se confirma antes de congelarla (SP-5).
 *
 * Apartados de $200 con $50 de inicial y cinco cuotas de $30, y una tasa BCV
 * de 42,50.
 */
final class RateSnapshotTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->staff = $this->within(fn (): User => User::factory()->create());
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-7: un pago aprobado y otro registrado a mano, los dos en bolívares.
     * La tasa sube —por la ingesta de verdad— y el negocio cambia de fuente
     * —por su acción de ajustes—: ningún saldo, ninguna cuota y ningún pago se
     * mueve. Y el pago siguiente suma lo suyo y nada más.
     */
    public function test_a_new_rate_moves_no_balance_on_either_path(): void
    {
        $approved = $this->activeLayaway();
        $manual = $this->activeLayaway();
        $this->approve($this->receiptFor($approved), '2550.00');
        $this->register($manual, '2550.00', Currency::VES);
        $before = [$this->stateOf($approved), $this->stateOf($manual)];
        $this->assertSame([11_000, 9_000], [$before[0]['layaway']['paid_cents'], $before[0]['layaway']['remaining_cents']]);
        $this->assertSame($before[0]['installments'], $before[1]['installments'], 'Los dos caminos dejan las mismas cuotas.');

        CarbonImmutable::setTestNow('2026-09-10 10:05:00');
        Http::fake(['ve.dolarapi.com/*' => Http::response([
            ['fuente' => 'oficial', 'promedio' => 50.0, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => '2026-09-10T10:01:00.000Z'],
            ['fuente' => 'paralelo', 'promedio' => 55.0, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => '2026-09-10T10:01:00.000Z'],
        ], 200)]);
        $this->withoutOrganization();
        app()->call([new FetchExchangeRate, 'handle']);
        $this->assertSame('50.00000000', ExchangeRate::query()->where('source', 'bcv')->latest('id')->value('rate'));
        $owner = $this->within(fn (): User => User::factory()->owner()->create());
        $this->within(fn (): array => app(UpdateExchangeRateSettingsAction::class)->handle(RateSource::Parallel, null, $owner));
        $this->withoutOrganization();
        $this->actingAs($this->staff)->get(route('layaways.show', $approved))->assertOk();

        $this->assertSame($before, [$this->stateOf($approved), $this->stateOf($manual)]);

        $this->register($approved, '30.00', Currency::USD);
        $after = $this->stateOf($approved);
        $this->assertSame([14_000, 6_000], [$after['layaway']['paid_cents'], $after['layaway']['remaining_cents']]);
        $this->assertSame($before[0]['payments'], array_slice($after['payments'], 0, 2));
    }

    /**
     * SP-5 por los dos caminos: con una tasa de hace 45 días, convertir
     * bolívares se confirma —esa tasa: fuente, valor y publicación— o no se
     * escribe nada. Con la tasa escrita a mano o en dólares no se pregunta, ni
     * al aprobar ni al registrar. Y la auditoría dice de cuándo era la tasa.
     */
    public function test_a_rate_older_than_30_days_is_confirmed_before_freezing_it(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);
        $published = $this->publishStaleRate();
        $token = 'bcv@40.00000000@'.$published->getTimestamp();

        $this->assertInstanceOf(UnconfirmedStaleRateException::class, $this->refused(fn () => $this->approve($receipt, '1200.00')));
        // El mismo instante con otro valor —la ingesta guarda otra fila si cambia— no es esa tasa.
        $this->assertInstanceOf(UnconfirmedStaleRateException::class, $this->refused(fn () => $this->approve($receipt, '1200.00', staleToken: 'bcv@41.00000000@'.$published->getTimestamp())));
        $this->assertInstanceOf(UnconfirmedStaleRateException::class, $this->refused(fn () => $this->register($layaway, '1200.00', Currency::VES)));
        $this->assertSame(1, DB::table('payments')->count(), 'Solo la inicial.');
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));

        $this->register($layaway, '10.00', Currency::USD);
        $this->register($layaway, '410.00', Currency::VES, rate: '41.00');
        $this->approve($this->receiptFor($layaway), '10.00', Currency::USD);
        $this->approve($this->receiptFor($layaway), '410.00', rate: '41.00');
        $this->approve($receipt, '1200.00', staleToken: $token);
        $this->register($layaway, '1200.00', Currency::VES, staleToken: $token);

        $frozen = DB::table('payments')->where('receipt_id', $receipt->getKey())->first(['rate_applied', 'rate_published_at']);
        $this->assertNotNull($frozen);
        $this->assertSame(['40.00000000', $published->toDateTimeString()], [$frozen->rate_applied, $frozen->rate_published_at]);
        $audited = $this->within(fn (): array => AuditEntry::query()->whereIn('event', [AuditEvent::ReceiptApproved->value, AuditEvent::PaymentRecorded->value])->orderByDesc('id')->limit(2)->get()
            ->map(fn (AuditEntry $entry): mixed => $entry->properties?->toArray()['changed']['rate_published_at']['after'] ?? null)->all());
        $this->assertSame([$published->toIso8601String(), $published->toIso8601String()], $audited);
    }

    /**
     * SP-5 por HTTP: la vista previa trae lo que hay que confirmar, aprobar sin
     * confirmarlo vuelve a su campo, y confirmándolo se aprueba.
     */
    public function test_the_review_screen_asks_to_confirm_an_old_rate(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway(), attributes: ['declared_amount' => '1200.00', 'declared_currency' => Currency::VES]);
        $token = 'bcv@40.00000000@'.$this->publishStaleRate()->getTimestamp();
        $this->actingAs($this->staff)->from(route('receipts.show', $receipt));

        $this->get(route('receipts.show', $receipt))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.staleRate.token', $token)
            ->where('preview.staleRate.days', 45));
        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '30.00', 'currency' => 'USD']))->assertOk()->assertJsonPath('preview.staleRate', null);
        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '1200.00', 'currency' => 'VES', 'rate' => '41.00']))->assertOk()->assertJsonPath('preview.staleRate', null);

        $this->post(route('receipts.approve', $receipt), ['amount' => '1200.00', 'currency' => 'VES'])
            ->assertSessionHasErrors(['confirm_stale_rate' => 'La tasa BCV que se usaría se publicó hace 45 días. Confírmala para convertir con ella, o escribe la tasa del día del pago.']);
        $this->withoutOrganization();
        $this->post(route('receipts.approve', $receipt), ['amount' => '1200.00', 'currency' => 'VES', 'confirm_stale_rate' => $token])
            ->assertRedirect(route('receipts.index'));
    }

    /**
     * SP-5 por HTTP en el pago manual: la vista previa lo trae —salvo con la
     * tasa escrita—, registrar sin confirmarlo vuelve a su campo, una
     * confirmación mal formada también, y confirmándolo se registra.
     */
    public function test_the_manual_payment_asks_to_confirm_an_old_rate(): void
    {
        $layaway = $this->activeLayaway();
        $token = 'bcv@40.00000000@'.$this->publishStaleRate()->getTimestamp();
        $payload = [
            'method' => 'pago_movil', 'amount' => '1200.00', 'currency' => 'VES', 'paid_at' => '2026-09-10',
            'note' => 'Pago móvil confirmado por teléfono con Ana.', 'idempotency_key' => (string) Str::uuid(),
        ];
        $this->actingAs($this->staff)->from(route('layaways.show', $layaway));

        $this->getJson(route('layaways.payments.preview', [$layaway, 'amount' => '1200.00', 'currency' => 'VES']))->assertOk()->assertJsonPath('preview.staleRate.token', $token);
        $this->getJson(route('layaways.payments.preview', [$layaway, 'amount' => '1200.00', 'currency' => 'VES', 'rate' => '41.00']))->assertOk()->assertJsonPath('preview.staleRate', null);

        $this->post(route('layaways.payments.store', $layaway), $payload)->assertSessionHasErrors('confirm_stale_rate');
        $this->withoutOrganization();
        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'confirm_stale_rate' => str_repeat('x', 101)])->assertSessionHasErrors('confirm_stale_rate');
        $this->assertSame(1, DB::table('payments')->count());

        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'confirm_stale_rate' => $token])->assertRedirect(route('layaways.show', $layaway));
        $this->assertSame(2, DB::table('payments')->count());
    }

    // -------------------------------------------------------------------

    /**
     * La única tasa del negocio pasa a ser una BCV de hace 45 días, a 40.
     */
    private function publishStaleRate(): CarbonImmutable
    {
        $published = CarbonImmutable::now()->subDays(45);
        DB::table('exchange_rates')->delete();
        Cache::flush();
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '40.00000000', 'published_at' => $published]);

        return $published;
    }

    private function approve(Receipt $receipt, string $amount, Currency $currency = Currency::VES, ?string $rate = null, ?string $staleToken = null): void
    {
        $this->within(fn () => app(ApproveReceiptAction::class)->handle(
            $receipt,
            new ApproveReceiptData($amount, $currency, $rate, null, confirmedStaleRate: $staleToken),
            $this->staff,
        ));
    }

    private function register(Layaway $layaway, string $amount, Currency $currency, ?string $rate = null, ?string $staleToken = null): void
    {
        $this->within(fn () => app(RegisterManualPaymentAction::class)->handle(
            $layaway,
            new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, $currency, $rate, CarbonImmutable::now(), 'Pago recibido en tienda por Ana.', (string) Str::uuid(), confirmedStaleRate: $staleToken),
            $this->staff,
        ));
    }

    /**
     * Activado con su inicial, registrada a mano.
     */
    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->register($layaway, '50.00', Currency::USD);

        return $layaway;
    }

    /**
     * El balance, las cuotas y los pagos de un apartado, tal cual en la base.
     *
     * @return array{layaway: array<string, mixed>, installments: list<array<string, mixed>>, payments: list<array<string, mixed>>}
     */
    private function stateOf(Layaway $layaway): array
    {
        return [
            'layaway' => (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['status', 'paid_cents', 'remaining_cents', 'next_due_date']),
            'installments' => DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['number', 'paid_cents', 'status'])->map(fn (object $row): array => (array) $row)->all(),
            'payments' => DB::table('payments')->where('layaway_id', $layaway->getKey())->orderBy('id')
                ->get(['amount_cents_usd', 'declared_amount', 'declared_currency', 'rate_applied', 'rate_source', 'rate_published_at'])->map(fn (object $row): array => (array) $row)->all(),
        ];
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
