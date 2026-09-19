<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\CurrencyDisplay;
use App\Domain\Settings\SettingKey;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La tasa y la preferencia de moneda en las page props (`REQ-FX-004` RB-4 y
 * RB-5).
 *
 * **El primer render del servidor ya tiene que ser el correcto.** Si la
 * preferencia se descubriera al montar el componente, la página abriría en
 * dólares y saltaría a bolívares un instante después, en cada visita de todo
 * el que eligiera bolívares.
 */
final class CurrencyPagePropsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);

        $plan = Plan::factory()->create();

        $this->withinOrganization($this->organization, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();

            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });

        $this->withoutOrganization();
    }

    /**
     * RB-5 · sin cookie manda el ajuste de la organización, que es el arranque
     * de quien todavía no ha elegido nada.
     */
    public function test_without_a_cookie_the_organization_default_wins(): void
    {
        $this->setDisplayDefault(CurrencyDisplay::VesPrimary);

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'ves_primary'));
    }

    /**
     * Y la cookie manda sobre el ajuste: la preferencia es **de la persona**
     * (RB-3), y el ajuste solo decide el arranque.
     */
    public function test_a_valid_cookie_wins_over_the_organization_default(): void
    {
        $this->setDisplayDefault(CurrencyDisplay::VesPrimary);

        $this->actingAs($this->owner())
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'usd_primary')
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'usd_primary'));
    }

    /**
     * **SP-3 · una cookie corrupta se descarta sin error.** No es un `422` ni
     * un log: quien la lleva no puede hacer nada al respecto y la página tiene
     * que abrir igual.
     */
    public function test_a_corrupt_cookie_falls_back_without_an_error(): void
    {
        $this->setDisplayDefault(CurrencyDisplay::VesPrimary);

        $this->actingAs($this->owner())
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'bolivares-por-favor')
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'ves_primary'));
    }

    public function test_an_empty_cookie_falls_back_too(): void
    {
        $this->actingAs($this->owner())
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, '')
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'usd_primary'));
    }

    /**
     * **La cookie no se parte por organización** (RB-3): quien navega dos
     * catálogos en bolívares no vuelve a pulsar el toggle en el segundo.
     */
    public function test_the_preference_survives_changing_organization(): void
    {
        $other = Organization::factory()->create();

        $plan = Plan::factory()->create();

        $this->withinOrganization($other, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();

            OrganizationSetting::query()->create([
                'key' => SettingKey::CurrencyDisplayDefault,
                // El otro negocio arranca en dólares, y da igual: quien ya
                // eligió bolívares los sigue viendo.
                'value' => CurrencyDisplay::UsdPrimary->value,
                'updated_by' => null,
            ]);
        });

        $otherOwner = $this->withinOrganization($other, fn (): User => User::factory()->owner()->create());

        $this->actingAs($otherOwner)
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'ves_primary')
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'ves_primary'));
    }

    /**
     * RB-5 · fuera de un tenant manda el default de plataforma. Marketing y el
     * acceso no tienen negocio del que tomar el arranque.
     */
    public function test_outside_a_tenant_the_platform_default_wins(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.display', 'usd_primary')
                ->where('currency.rate.rate', null));
    }

    /**
     * La tasa viaja con la preferencia, en la forma de `RateView`, y con el
     * nombre de la fuente ya resuelto desde el enum.
     */
    public function test_the_rate_travels_with_its_source_label(): void
    {
        $this->persistRate('42.50000000');

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.rate.rate', 42.5)
                ->where('currency.rate.source', 'bcv')
                ->where('currency.rate.sourceLabel', 'BCV')
                ->where('currency.rate.isStale', false)
                ->has('currency.rate.publishedAt'));
    }

    /**
     * Sin tasa, `rate` es `null` y no cero: es lo que el cliente lee para
     * ocultar el bolívar y deshabilitar el toggle (`REQ-FX-005` RB-4).
     */
    public function test_without_a_rate_the_prop_is_null(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.rate.rate', null)
                ->where('currency.rate.publishedAt', null)
                ->where('currency.rate.isStale', false));
    }

    /**
     * **La zona del sello es la del negocio** (`organizations.timezone`): el
     * BCV publica en hora de Venezuela y el catálogo es de un negocio
     * venezolano, así que quien mire desde Madrid tiene que leer la misma hora
     * que dice el BCV.
     */
    public function test_the_timezone_is_the_organizations(): void
    {
        $this->organization->forceFill(['timezone' => 'America/Bogota'])->save();

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.timezone', 'America/Bogota'));
    }

    public function test_outside_a_tenant_the_timezone_is_the_applications(): void
    {
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.timezone', config('app.timezone')));
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    private function setDisplayDefault(CurrencyDisplay $display): void
    {
        $this->withinOrganization($this->organization, function () use ($display): void {
            OrganizationSetting::query()->updateOrCreate(
                ['key' => SettingKey::CurrencyDisplayDefault->value],
                ['value' => $display->value],
            );
        });

        app(\App\Support\Settings::class)->forget($this->organization);
    }

    private function persistRate(string $rate): void
    {
        ExchangeRate::query()->create([
            'source' => RateSource::Bcv,
            'rate' => $rate,
            'published_at' => CarbonImmutable::now()->subHour(),
        ]);

        Cache::flush();
    }
}
