<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentCorrectionPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_el_detalle_ofrece_corregir_con_los_datos_del_pago(): void
    {
        [$layaway, $payment] = $this->paidLayaway();

        $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.correction.storeUrl', route('payments.correct', $payment))
            ->where('payments.0.correction.values.amount', '60,00')
            ->where('payments.0.correction.values.method', 'efectivo_usd')
            ->where('payments.0.correction.values.paid_at', '2026-09-10'));
    }

    public function test_la_vista_previa_de_la_correccion_no_anula_el_pago(): void
    {
        [, $payment] = $this->paidLayaway();

        $this->getJson(route('payments.correct.preview', $payment).'?amount=30,00&currency=USD&paid_at=2026-09-10')
            ->assertOk()
            ->assertJsonPath('preview.unallocated', null);

        $this->assertNull(DB::table('payments')->where('id', $payment->getKey())->value('voided_at'));
    }

    public function test_mueve_el_pago_al_apartado_que_se_busca_por_codigo(): void
    {
        [$from, $payment] = $this->paidLayaway();
        [$to] = $this->paidLayaway();

        $this->getJson(route('payments.target', $payment).'?codigo='.strtolower($to->code))
            ->assertOk()
            ->assertJsonPath('layaway.code', $to->code)
            ->assertJsonPath('options.storeUrl', route('payments.correct', ['payment' => $payment, 'destino' => $to->code]));
        $this->getJson(route('payments.target', $payment).'?codigo='.$from->code)->assertStatus(422);

        $this->post(route('payments.correct', ['payment' => $payment, 'destino' => $to->code]), $this->fields('60,00'))
            ->assertRedirect(route('layaways.show', $to))
            ->assertSessionHas('status', sprintf('Pago de $60,00 movido a %s.', $to->code));

        $this->assertNotNull(DB::table('payments')->where('id', $payment->getKey())->value('voided_at'));
        $this->assertSame(3, DB::table('payments')->where('layaway_id', $to->getKey())->whereNull('voided_at')->count());
    }

    public function test_mover_un_pago_movil_con_su_misma_referencia_no_lo_toma_por_duplicado(): void
    {
        [, $first] = $this->paidLayaway();
        $from = Layaway::query()->withoutGlobalScopes()->findOrFail($first->layaway_id);
        [$to] = $this->paidLayaway();
        $mobile = ['method' => 'pago_movil', 'bank' => 'Banco de Venezuela', 'reference' => '007881968733', 'currency' => 'VES', 'amount' => '1.275,00'];

        $this->post(route('layaways.payments.store', $from), [...$this->fields('1.275,00'), ...$mobile])->assertRedirect(route('layaways.show', $from));
        $this->withoutOrganization();
        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->where('layaway_id', $from->getKey())->latest('id')->firstOrFail());
        $this->withoutOrganization();

        $this->post(route('payments.correct', ['payment' => $payment, 'destino' => $to->code]), [...$this->fields('1.275,00'), ...$mobile])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('layaways.show', $to));

        $this->assertNotNull(DB::table('payments')->where('id', $payment->getKey())->value('voided_at'));
        $this->assertSame(1, DB::table('payments')->where('layaway_id', $to->getKey())->where('reference', '007881968733')->whereNull('voided_at')->count());
    }

    public function test_el_pago_de_un_apartado_cancelado_se_mueve_al_apartado_correcto(): void
    {
        [$from, $payment] = $this->paidLayaway();
        [$to] = $this->paidLayaway();
        DB::table('layaways')->where('id', $from->getKey())->update(['status' => 'cancelled']);

        $this->post(route('payments.correct', ['payment' => $payment, 'destino' => $to->code]), $this->fields('60,00'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('layaways.show', $to));

        $this->assertSame('cancelled', DB::table('layaways')->where('id', $from->getKey())->value('status'));
        $this->assertNotNull(DB::table('payments')->where('id', $payment->getKey())->value('voided_at'));
        $this->assertSame(3, DB::table('payments')->where('layaway_id', $to->getKey())->whereNull('voided_at')->count());
    }

    public function test_un_codigo_que_no_existe_se_explica_en_el_campo(): void
    {
        [, $payment] = $this->paidLayaway();

        $this->post(route('payments.correct', ['payment' => $payment, 'destino' => 'AP-NOEXISTE']), $this->fields('60,00'))
            ->assertSessionHasErrors(['destino' => 'No hay un apartado con ese código en tu negocio.']);
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $amount): array
    {
        return [
            'method' => 'efectivo_usd', 'amount' => $amount, 'currency' => 'USD', 'paid_at' => '2026-09-10',
            'note' => 'Era el pago de otra clienta.', 'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * @return array{0: Layaway, 1: Payment}
     */
    private function paidLayaway(): array
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member);

        foreach (['50.00', '60.00'] as $amount) {
            $this->post(route('layaways.payments.store', $layaway), $this->fields($amount))->assertRedirect(route('layaways.show', $layaway));
            $this->withoutOrganization();
        }

        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->where('layaway_id', $layaway->getKey())->latest('id')->firstOrFail());
        $this->withoutOrganization();

        return [$layaway, $payment];
    }
}
