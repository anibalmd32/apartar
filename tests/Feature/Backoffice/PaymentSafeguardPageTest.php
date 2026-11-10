<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Http\Controllers\Backoffice\PaymentSafeguardController;
use App\Models\Catalog;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentSafeguardPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    private Layaway $layaway;

    private Catalog $catalog;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00:00', 'America/Caracas'));
        Storage::fake(Receipt::DISK);
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->create(['safeguard_enabled' => true]));
        $this->layaway = $this->layawayIn($this->organization);
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['catalog_id' => $this->catalog->getKey()]);
        ExchangeRate::factory()->create(['source' => RateSource::Parallel, 'rate' => '1000.00000000', 'published_at' => CarbonImmutable::parse('2026-11-03 04:00:00')]);
        $this->payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::factory()->create([
            'layaway_id' => $this->layaway->getKey(),
            'amount_cents_usd' => new Money(10_000),
            'declared_currency' => Currency::VES,
            'declared_amount' => '89000.00',
            'rate_applied' => '890.00000000',
            'paid_on' => '2026-11-03',
        ]));
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_el_apartado_muestra_cuanto_vale_cada_pago_en_paralelo_y_deja_registrar_el_resguardo(): void
    {
        $this->actingAs($this->member);

        $this->get(route('layaways.show', $this->layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.safeguard.equivalent', '$89,00')
            ->where('payments.0.safeguard.gap', '-$11,00')
            ->where('payments.0.safeguard.parallelRate', '1000.00000000')
            ->where('payments.0.safeguard.record', null)
            ->where('safeguardOptions.destinations.0', ['value' => 'binance_usdt', 'label' => 'Binance (USDT)']));

        $this->from(route('layaways.show', $this->layaway))
            ->post(route('payments.safeguard.store', $this->payment), [
                'destination' => 'binance_usdt',
                'reference' => 'ORD-77',
                'note' => 'Comprado en P2P',
                'proof' => UploadedFile::fake()->image('p2p.png'),
            ])
            ->assertRedirect(route('layaways.show', $this->layaway))
            ->assertSessionHas('status', PaymentSafeguardController::SAVED);

        $proofUrl = null;
        $this->get(route('layaways.show', $this->layaway))->assertInertia(function (AssertableInertia $page) use (&$proofUrl): void {
            $page->where('payments.0.safeguard.record.destinationLabel', 'Binance (USDT)')
                ->where('payments.0.safeguard.record.reference', 'ORD-77')
                ->where('payments.0.safeguard.record.note', 'Comprado en P2P');
            $proofUrl = $page->toArray()['props']['payments'][0]['safeguard']['record']['proofUrl'];
        });

        $this->assertIsString($proofUrl);
        $this->get($proofUrl)->assertOk();

        $this->delete(route('payments.safeguard.destroy', $this->payment))->assertSessionHas('status', PaymentSafeguardController::REMOVED);
        $this->assertSame(0, DB::table('payment_safeguards')->count());
    }

    public function test_sin_resguardo_en_el_catalogo_el_apartado_no_lo_muestra_ni_lo_acepta(): void
    {
        DB::table('catalogs')->where('id', $this->catalog->getKey())->update(['safeguard_enabled' => false]);
        $this->actingAs($this->member);

        $this->get(route('layaways.show', $this->layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.0.safeguard', null)
            ->where('safeguardOptions', null));

        $this->post(route('payments.safeguard.store', $this->payment), ['destination' => 'zelle'])->assertSessionHasErrors('destination');
        $this->post(route('payments.safeguard.store', $this->payment), ['destination' => 'lingotes'])->assertSessionHasErrors('destination');
        $this->assertSame(0, DB::table('payment_safeguards')->count());
    }

    public function test_otro_negocio_no_ve_ni_toca_el_resguardo(): void
    {
        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($stranger)->post(route('payments.safeguard.store', $this->payment), ['destination' => 'zelle'])->assertNotFound();
        $this->assertSame(0, DB::table('payment_safeguards')->count());
    }
}
