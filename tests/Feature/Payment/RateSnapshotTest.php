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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RateSnapshotTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

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

    public function test_a_rate_older_than_30_days_is_confirmed_before_freezing_it(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);
        $published = $this->publishStaleRate();
        $token = 'bcv@40.00000000@'.$published->getTimestamp();

        $this->assertInstanceOf(UnconfirmedStaleRateException::class, $this->refused(fn () => $this->approve($receipt, '1200.00')));
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

    public function test_a_manual_rate_far_from_the_day_rate_is_confirmed_before_approving(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway(), attributes: ['declared_amount' => '120.00', 'declared_currency' => Currency::VES]);
        $this->actingAs($this->staff)->from(route('receipts.show', $receipt));
        $before = DB::table('payments')->count();

        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '120.00', 'currency' => 'VES', 'rate' => '45.00']))->assertOk()->assertJsonPath('preview.rateDeviation', null);
        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '120.00', 'currency' => 'VES', 'rate' => '4.25']))->assertOk()
            ->assertJsonPath('preview.rateDeviation.token', '4.25@42.50000000')
            ->assertJsonPath('preview.rateDeviation.percent', 90);

        $this->post(route('receipts.approve', $receipt), ['amount' => '120.00', 'currency' => 'VES', 'rate' => '4.25'])
            ->assertSessionHasErrors(['confirm_rate_deviation' => 'La tasa que escribiste (4,25) está 90 % por debajo de la tasa BCV del día (42,50). Confírmala si es correcta.']);
        $this->withoutOrganization();
        $this->assertSame($before, DB::table('payments')->count());

        $this->post(route('receipts.approve', $receipt), ['amount' => '120.00', 'currency' => 'VES', 'rate' => '4.25', 'confirm_rate_deviation' => '4.25@42.50000000'])
            ->assertRedirect(route('receipts.index'));
        $this->assertSame($before + 1, DB::table('payments')->count());
    }

    public function test_the_manual_payment_confirms_a_manual_rate_far_from_the_day_rate(): void
    {
        $layaway = $this->activeLayaway();
        $payload = [
            'method' => 'pago_movil', 'amount' => '120.00', 'currency' => 'VES', 'rate' => '425.00', 'paid_at' => '2026-09-10',
            'note' => 'Pago móvil confirmado por teléfono con Ana.', 'idempotency_key' => (string) Str::uuid(),
        ];
        $this->actingAs($this->staff)->from(route('layaways.show', $layaway));
        $before = DB::table('payments')->count();

        $this->post(route('layaways.payments.store', $layaway), $payload)->assertSessionHasErrors('confirm_rate_deviation');
        $this->withoutOrganization();
        $this->assertSame($before, DB::table('payments')->count());

        $this->post(route('layaways.payments.store', $layaway), [...$payload, 'confirm_rate_deviation' => '425.00@42.50000000'])->assertRedirect(route('layaways.show', $layaway));
        $this->assertSame($before + 1, DB::table('payments')->count());
    }

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

    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->register($layaway, '50.00', Currency::USD);

        return $layaway;
    }

    /**
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
