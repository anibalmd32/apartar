<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Actions\Payment\CorrectPaymentAction;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CorrectPaymentActionTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_editar_cambia_el_importe_y_el_saldo_cuenta_solo_el_corregido(): void
    {
        $layaway = $this->activeLayaway();
        $wrong = $this->pay($layaway, '45.00');

        $corrected = $this->correct($wrong, $layaway, '30.00');

        $this->assertNotNull(DB::table('payments')->where('id', $wrong->getKey())->value('voided_at'));
        $this->assertStringStartsWith('Se corrigió:', (string) DB::table('payments')->where('id', $wrong->getKey())->value('void_reason'));
        $this->assertSame(3_000, $corrected->amount_cents_usd->cents);
        $this->assertSame([[1, 3_000, 'paid'], [2, 0, 'pending']], array_slice($this->installmentsOf($layaway), 0, 2));
        $this->assertSame(8_000, (int) DB::table('layaways')->where('id', $layaway->getKey())->value('paid_cents'));
    }

    public function test_mover_un_pago_manual_lo_saca_de_un_apartado_y_lo_aplica_en_otro(): void
    {
        $from = $this->activeLayaway();
        $to = $this->activeLayaway();
        $wrong = $this->pay($from, '30.00');

        $moved = $this->correct($wrong, $to, '30.00');

        $this->assertSame((int) $to->getKey(), (int) $moved->layaway_id);
        $this->assertSame(0, $this->installmentsOf($from)[0][1]);
        $this->assertSame([1, 3_000, 'paid'], $this->installmentsOf($to)[0]);
        $this->assertStringStartsWith('Se movió a '.$to->code, (string) DB::table('payments')->where('id', $wrong->getKey())->value('void_reason'));
    }

    public function test_mover_el_pago_de_un_comprobante_lleva_el_comprobante_al_otro_cliente(): void
    {
        $from = $this->activeLayaway();
        $to = $this->activeLayaway();
        $receipt = $this->receiptFor($from, attributes: ['declared_amount' => '30.00', 'declared_currency' => Currency::USD]);
        $wrong = $this->within(fn (): Payment => app(ApproveReceiptAction::class)->handle($receipt, new ApproveReceiptData('30.00', Currency::USD, null, null), $this->staff)->payment);

        $moved = $this->correct($wrong, $to, '30.00');

        $this->assertSame((int) $receipt->getKey(), (int) $moved->receipt_id);
        $this->assertSame(
            ['layaway_id' => (int) $to->getKey(), 'customer_id' => (int) $to->customer_id, 'status' => 'approved'],
            array_map(fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, (array) DB::table('receipts')->where('id', $receipt->getKey())->first(['layaway_id', 'customer_id', 'status'])),
        );
        $this->assertSame(0, $this->installmentsOf($from)[0][1]);
        $this->assertSame([1, 3_000, 'paid'], $this->installmentsOf($to)[0]);
    }

    public function test_si_la_correccion_falla_el_pago_original_sigue_intacto(): void
    {
        $layaway = $this->activeLayaway();
        $original = $this->pay($layaway, '30.00');

        try {
            $this->correct($original, $layaway, '9000.00');
            $this->fail('Tenía que pedir confirmar el sobrante.');
        } catch (UnconfirmedExcessException) {
        }

        $this->assertNull(DB::table('payments')->where('id', $original->getKey())->value('voided_at'));
        $this->assertSame([1, 3_000, 'paid'], $this->installmentsOf($layaway)[0]);
    }

    private function correct(Payment $payment, Layaway $target, string $amount): Payment
    {
        return $this->within(fn (): Payment => app(CorrectPaymentAction::class)->handle(
            $payment,
            $target,
            new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, Currency::USD, null, CarbonImmutable::now(), 'Era el pago de otra clienta.', (string) Str::uuid()),
            $this->staff,
        ));
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

    /**
     * @return list<array{int, int, string}>
     */
    private function installmentsOf(Layaway $layaway): array
    {
        return DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['number', 'paid_cents', 'status'])
            ->map(fn (object $row): array => [(int) $row->number, (int) $row->paid_cents, (string) $row->status])->all();
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
