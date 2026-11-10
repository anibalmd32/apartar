<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Safeguard\SafeguardDestination;
use App\Models\Catalog;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentSafeguard;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogSummaryPeriodTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    private Catalog $catalog;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:00:00', 'America/Caracas'));
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        [$this->catalog, $product] = $this->withinOrganization($this->organization, function (): array {
            $catalog = Catalog::factory()->published()->create();
            $product = Product::factory()->create(['stock' => 10]);
            $catalog->products()->attach($product, ['position' => 1]);

            return [$catalog, $product];
        });
        $this->layaway = $this->layawayIn($this->organization, LayawayStatus::Active, $product);
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['catalog_id' => $this->catalog->getKey(), 'created_at' => '2026-10-05 10:00:00']);
        ExchangeRate::factory()->create(['source' => RateSource::Parallel, 'rate' => '1000.00000000', 'published_at' => CarbonImmutable::parse('2026-10-10 04:00:00')]);

        $this->pay('2026-10-10', 10_000, '89000.00');
        $this->pay('2026-11-02', 5_000);
        $this->pay('2026-05-20', 2_000);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_por_defecto_resume_los_ultimos_seis_meses_por_mes(): void
    {
        $this->actingAs($this->owner)->get(route('catalogs.summary', $this->catalog))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('period.from', '2026-06-01')
            ->where('period.to', '2026-11-03')
            ->where('period.preset', '6m')
            ->where('period.granularity', 'month')
            ->has('series', 6)
            ->where('series.4.label', 'oct 26')
            ->where('series.4.collected.cents', 10_000)
            ->where('series.4.layaways', 1)
            ->where('series.5.collected.cents', 5_000)
            ->where('periodTotals.collected.cents', 15_000)
            ->where('periodTotals.payments', 2)
            ->where('periodTotals.average.cents', 7_500)
            ->where('safeguard', null)
            ->where('payments.0.amount.cents', 5_000)
            ->where('payments.1.declared', 'Bs. 89.000,00')
            ->where('products.0.parallel', null));
    }

    public function test_un_rango_corto_se_agrupa_por_dia_y_se_puede_pedir_desde_el_primer_pago(): void
    {
        $this->actingAs($this->owner)->get(route('catalogs.summary', ['catalog' => $this->catalog, 'desde' => '2026-11-01', 'hasta' => '2026-11-03']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('period.granularity', 'day')
                ->where('period.preset', 'mes')
                ->has('series', 3)
                ->where('series.1.label', '2 nov')
                ->where('series.1.collected.cents', 5_000)
                ->where('periodTotals.payments', 1)
                ->where('period.presets.5.from', '2026-05-20'));
    }

    public function test_con_el_resguardo_activo_suma_lo_que_vale_en_paralelo_y_donde_esta(): void
    {
        DB::table('catalogs')->where('id', $this->catalog->getKey())->update(['safeguard_enabled' => true]);
        $october = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->where('paid_on', '2026-10-10')->sole());
        $this->withinOrganization($this->organization, fn () => (new PaymentSafeguard)->forceFill([
            'payment_id' => $october->getKey(),
            'destination' => SafeguardDestination::BinanceUsdt,
        ])->save());
        $this->withoutOrganization();

        $this->actingAs($this->owner)->get(route('catalogs.summary', ['catalog' => $this->catalog, 'desde' => '2026-05-01', 'hasta' => '2026-11-03']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('safeguard.parallel.cents', 15_900)
                ->where('safeguard.gap.cents', -1_100)
                ->where('safeguard.payments', 3)
                ->where('safeguard.recorded', 1)
                ->where('safeguard.withoutRate', 0)
                ->where('safeguard.byDestination.0.label', 'Binance (USDT)')
                ->where('safeguard.byDestination.0.amount.cents', 8_900)
                ->where('safeguard.byDestination.1.label', 'Sin registrar')
                ->where('safeguard.byDestination.1.amount.cents', 7_000)
                ->where('products.0.parallel.cents', 15_900)
                ->where('payments.1.parallel', '$89,00')
                ->where('payments.1.safeguard', 'Binance (USDT)')
                ->where('series.5.parallel.cents', 8_900));
    }

    private function pay(string $day, int $cents, ?string $bolivars = null): void
    {
        $this->withinOrganization($this->organization, fn (): Payment => Payment::factory()->create([
            'layaway_id' => $this->layaway->getKey(),
            'amount_cents_usd' => new Money($cents),
            'declared_currency' => $bolivars === null ? Currency::USD : Currency::VES,
            'declared_amount' => $bolivars ?? sprintf('%d.%02d', intdiv($cents, 100), $cents % 100),
            'rate_applied' => $bolivars === null ? '1.00000000' : '890.00000000',
            'paid_on' => $day,
            'settled_at' => CarbonImmutable::parse($day.' 12:00:00'),
        ]));
    }
}
