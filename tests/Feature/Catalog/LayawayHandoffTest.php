<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Account;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Support\LayawayHandoff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LayawayHandoffTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-27 10:00:00');
        Notification::fake();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['name' => 'Tienda Mabe']);

        $this->catalog = $this->withinOrganization($this->organization, function (): Catalog {
            Subscription::factory()->forPlan(Plan::factory()->create(['max_active_layaways' => 5]))->create();
            PaymentAccount::factory()->create();

            $catalog = Catalog::factory()->published()->create();
            $product = Product::factory()->published()->create([
                'slug' => 'nevera-mabe',
                'price_cents' => 15_000,
                'down_payment_cents' => 10_000,
                'installments_count' => 3,
                'frequency_days' => 15,
                'stock' => 5,
            ]);
            $catalog->products()->attach($product, ['position' => 1]);

            return $catalog;
        });

        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_continuar_al_pago_abre_el_portal_del_apartado_sin_pedir_la_cédula(): void
    {
        $code = $this->reserve();

        $this->get($this->thanksUrl($code))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payUrl', route('catalog.layaway.continue', ['catalog' => $this->catalog->slug, 'code' => $code])));

        $this->get($this->continueUrl($code))->assertRedirect(route('portal.pay', ['codigo' => $code]));

        $this->assertAuthenticatedAs($this->holderOf($code), 'customer');
        $this->get(route('portal.pay', ['codigo' => $code]))->assertRedirect(route('portal.receipts.create', ['code' => $code]));
    }

    public function test_el_enlace_sirve_una_sola_vez(): void
    {
        $code = $this->reserve();

        $this->get($this->continueUrl($code))->assertRedirect(route('portal.pay', ['codigo' => $code]));
        Auth::guard('customer')->logout();

        $this->get($this->continueUrl($code))->assertRedirect(route('portal.access', ['codigo' => $code]));
        $this->assertGuest('customer');
    }

    public function test_el_enlace_caduca_a_la_media_hora(): void
    {
        $code = $this->reserve();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(LayawayHandoff::MINUTES + 1));

        $this->get($this->continueUrl($code))->assertRedirect(route('portal.access', ['codigo' => $code]));
        $this->assertGuest('customer');
    }

    public function test_otra_sesión_no_puede_entrar_con_el_código(): void
    {
        $code = $this->reserve();

        $this->flushSession();

        $this->get($this->continueUrl($code))->assertRedirect(route('portal.access', ['codigo' => $code]));
        $this->assertGuest('customer');
    }

    public function test_cambiar_el_código_del_enlace_no_abre_otro_apartado(): void
    {
        $mine = $this->reserve();
        $theirs = $this->reserve(['documentNumber' => '20.123.456', 'email' => 'otra@ejemplo.test']);
        $session = session(LayawayHandoff::SESSION_KEY);
        unset($session[$theirs]);
        session([LayawayHandoff::SESSION_KEY => $session]);

        $this->get($this->continueUrl($theirs))->assertRedirect(route('portal.access', ['codigo' => $theirs]));
        $this->assertGuest('customer');

        $this->get($this->continueUrl('AP-ZZZZZZZZ'))->assertRedirect(route('portal.access', ['codigo' => 'AP-ZZZZZZZZ']));
        $this->assertGuest('customer');

        $this->get($this->continueUrl($mine))->assertRedirect(route('portal.pay', ['codigo' => $mine]));
    }

    public function test_el_código_de_un_apartado_de_otro_catálogo_no_entra(): void
    {
        $code = $this->reserve();
        $other = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());
        $this->withoutOrganization();

        $this->get("/c/{$other->slug}/apartar/{$code}/continuar")->assertRedirect(route('portal.access', ['codigo' => $code]));
        $this->assertGuest('customer');
    }

    public function test_con_una_cuenta_abierta_sigue_al_acceso_que_ya_la_reconoce(): void
    {
        $this->actingAs(Account::factory()->create(), 'account');
        $code = $this->reserve();

        $this->get($this->continueUrl($code))->assertRedirect(route('portal.access', ['codigo' => $code]));
        $this->assertGuest('customer');
    }

    public function test_si_hay_que_escribir_la_cédula_el_acceso_muestra_el_negocio_del_apartado_recién_hecho(): void
    {
        $code = $this->reserve();

        $this->get(route('portal.access', ['codigo' => $code]))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/Access')
            ->where('businessName', 'Tienda Mabe'));

        $this->flushSession();

        $this->get(route('portal.access', ['codigo' => $code]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('businessName', null));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reserve(array $overrides = []): string
    {
        $before = $this->codes();

        $this->post("/c/{$this->catalog->slug}/apartar/nevera-mabe", array_merge([
            'quantity' => 1,
            'fullName' => 'María González',
            'documentNumber' => '12.345.678',
            'phone' => '0414-1234567',
            'email' => 'maria@ejemplo.test',
            'termsAccepted' => true,
            'idempotencyKey' => (string) Str::uuid(),
        ], $overrides))->assertRedirect();

        $this->withoutOrganization();

        $created = array_values(array_diff($this->codes(), $before));
        $this->assertCount(1, $created);

        return $created[0];
    }

    /**
     * @return list<string>
     */
    private function codes(): array
    {
        $codes = $this->withinOrganization($this->organization, fn (): array => Layaway::query()->pluck('code')->all());
        $this->withoutOrganization();

        return array_values(array_map(strval(...), $codes));
    }

    private function holderOf(string $code): Customer
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()
            ->findOrFail(Layaway::query()->where('code', $code)->value('customer_id')));
        $this->withoutOrganization();

        return $customer;
    }

    private function thanksUrl(string $code): string
    {
        return "/c/{$this->catalog->slug}/apartar/{$code}/gracias";
    }

    private function continueUrl(string $code): string
    {
        return "/c/{$this->catalog->slug}/apartar/{$code}/continuar";
    }
}
