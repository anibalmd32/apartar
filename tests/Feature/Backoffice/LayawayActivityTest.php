<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Actions\Layaway\RescheduleInstallmentAction;
use App\Actions\Payment\CorrectPaymentAction;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\ReallocatePaymentAction;
use App\Actions\Payment\RecordSafeguardAction;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Actions\Payment\RemoveSafeguardAction;
use App\Actions\Payment\SafeguardData;
use App\Actions\Payment\VoidPaymentAction;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Safeguard\SafeguardDestination;
use App\Models\Catalog;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Queries\LayawayActivityQuery;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LayawayActivityTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const REASON = 'Era el pago de otra clienta.';

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->staff = $this->within(fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_editar_un_pago_queda_como_un_solo_evento_con_el_antes_y_el_despues(): void
    {
        $layaway = $this->activeLayaway();
        $wrong = $this->pay($layaway, '45.00');
        CarbonImmutable::setTestNow('2026-09-11 10:00:00');

        $this->correct($wrong, $layaway, '30.00');

        $edited = $this->eventsOf($layaway, 'payment_edited');
        $this->assertCount(1, $edited);
        $this->assertSame('Pago editado · $45,00 → $30,00', $edited[0]['title']);
        $this->assertSame('Motivo: '.self::REASON, $edited[0]['detail']);
        $this->assertSame('Ana Pérez', $edited[0]['actor']);
        $this->assertSame([], $this->eventsOf($layaway, 'payment_voided'));
        $this->assertCount(2, $this->eventsOf($layaway, 'payment_recorded'));
    }

    public function test_transferir_un_pago_se_ve_en_los_dos_apartados_con_enlace_al_otro(): void
    {
        $from = $this->activeLayaway();
        $to = $this->activeLayaway();
        $payment = $this->pay($from, '30.00');

        $this->correct($payment, $to, '30.00');

        $out = $this->eventsOf($from, 'payment_transferred_out');
        $this->assertCount(1, $out);
        $this->assertSame(sprintf('Pago transferido al apartado %s · $30,00', $to->code), $out[0]['title']);
        $this->assertSame('Motivo: '.self::REASON, $out[0]['detail']);
        $this->assertSame('Ana Pérez', $out[0]['actor']);
        $this->assertSame(['url' => route('layaways.show', $to), 'label' => $to->code], $out[0]['link']);

        $in = $this->eventsOf($to, 'payment_transferred_in');
        $this->assertCount(1, $in);
        $this->assertSame('Recibió un pago transferido · $30,00', $in[0]['title']);
        $this->assertSame(sprintf('Estaba en el apartado %s. Motivo: %s', $from->code, self::REASON), $in[0]['detail']);
        $this->assertSame(['url' => route('layaways.show', $from), 'label' => $from->code], $in[0]['link']);
        $this->assertCount(1, $this->eventsOf($to, 'payment_recorded'));
    }

    public function test_anular_reasignar_y_reprogramar_dejan_su_rastro_con_quien_lo_hizo(): void
    {
        $layaway = $this->activeLayaway();
        $payment = $this->pay($layaway, '30.00');
        $mistake = $this->pay($layaway, '10.00');

        $this->within(fn () => app(VoidPaymentAction::class)->handle($mistake, $this->staff, 'Se registró dos veces.'));
        $this->within(fn () => app(ReallocatePaymentAction::class)->handle(
            $payment,
            [['installmentId' => $this->installment($layaway, 2)->getKey(), 'cents' => 3_000]],
            $this->staff,
            'Lo pagó para la segunda cuota.',
        ));
        $this->within(fn () => app(RescheduleInstallmentAction::class)->handle($this->installment($layaway, 3), CarbonImmutable::parse('2026-10-28'), $this->staff, 'Le pagan a fin de mes.'));

        $voided = $this->eventsOf($layaway, 'payment_voided')[0];
        $this->assertSame(['Pago anulado · $10,00', 'Motivo: Se registró dos veces.', 'Ana Pérez'], [$voided['title'], $voided['detail'], $voided['actor']]);

        $moved = $this->eventsOf($layaway, 'payment_reallocated')[0];
        $this->assertSame('Pago reasignado entre cuotas · $30,00', $moved['title']);
        $this->assertSame('Antes: cuota 1 $30,00. Ahora: cuota 2 $30,00. Motivo: Lo pagó para la segunda cuota.', $moved['detail']);
        $this->assertSame('Ana Pérez', $moved['actor']);

        $rescheduled = $this->eventsOf($layaway, 'installment_rescheduled')[0];
        $this->assertSame('Cuota 3 reprogramada', $rescheduled['title']);
        $this->assertStringEndsWith('al 28 oct 2026. Motivo: Le pagan a fin de mes.', (string) $rescheduled['detail']);
    }

    public function test_el_resguardo_registrado_editado_y_borrado_aparece_en_la_actividad(): void
    {
        $layaway = $this->activeLayaway();
        $catalog = $this->within(fn (): Catalog => Catalog::factory()->create(['safeguard_enabled' => true]));
        DB::table('layaways')->where('id', $layaway->getKey())->update(['catalog_id' => $catalog->getKey()]);
        $payment = $this->pay($layaway->refresh(), '30.00');

        $this->within(fn () => app(RecordSafeguardAction::class)->handle($payment, new SafeguardData(SafeguardDestination::BinanceUsdt, 'ORD-1'), $this->staff));
        CarbonImmutable::setTestNow('2026-09-10 11:00:00');
        $this->within(fn () => app(RecordSafeguardAction::class)->handle($payment, new SafeguardData(SafeguardDestination::Zelle, 'Z-2'), $this->staff));
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        $this->within(fn () => app(RemoveSafeguardAction::class)->handle($payment, $this->staff));

        $recorded = $this->eventsOf($layaway, 'safeguard_recorded');
        $this->assertCount(1, $recorded);
        $this->assertSame(['Resguardo registrado · Binance (USDT)', 'Pago de $30,00. ref. ORD-1', 'Ana Pérez'], [$recorded[0]['title'], $recorded[0]['detail'], $recorded[0]['actor']]);
        $this->assertSame('Resguardo editado · Zelle', $this->eventsOf($layaway, 'safeguard_edited')[0]['title']);
        $this->assertSame(['Resguardo borrado', 'Pago de $30,00'], [$this->eventsOf($layaway, 'safeguard_removed')[0]['title'], $this->eventsOf($layaway, 'safeguard_removed')[0]['detail']]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsOf(Layaway $layaway, string $kind): array
    {
        $events = $this->within(fn (): array => app(LayawayActivityQuery::class)->for($layaway));

        return array_values(array_filter($events, fn (array $event): bool => $event['kind'] === $kind));
    }

    private function correct(Payment $payment, Layaway $target, string $amount): Payment
    {
        return $this->within(fn (): Payment => app(CorrectPaymentAction::class)->handle(
            $payment,
            $target,
            new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, Currency::USD, null, CarbonImmutable::now(), self::REASON, (string) Str::uuid()),
            $this->staff,
        ));
    }

    private function pay(Layaway $layaway, string $amount): Payment
    {
        return $this->within(fn (): Payment => app(RegisterManualPaymentAction::class)->handle(
            $layaway,
            new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, Currency::USD, null, CarbonImmutable::now(), null, (string) Str::uuid()),
            $this->staff,
        ));
    }

    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->pay($layaway, '50.00');

        return $layaway;
    }

    private function installment(Layaway $layaway, int $number): Installment
    {
        return $this->within(fn (): Installment => Installment::query()->where('layaway_id', $layaway->getKey())->where('number', $number)->firstOrFail());
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
