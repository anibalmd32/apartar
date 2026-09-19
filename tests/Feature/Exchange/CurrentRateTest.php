<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * La tasa de **esta** respuesta, resuelta una sola vez.
 *
 * Existe porque en una misma página la tasa se usa dos veces y para dos cosas:
 * la pinta el sello y la multiplica cada importe. Si el sello saliera de una
 * consulta y los importes de otra, bastaría que la ingesta insertara entre las
 * dos para que la página estampara una tasa distinta de la que multiplicó — y
 * ese es el fallo más caro de todos, porque **el número es creíble**.
 */
final class CurrentRateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $this->withinOrganization($this->organization, function (): void {
            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });
    }

    public function test_it_resolves_the_rate_of_the_active_organization(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $this->assertSame('42.50000000', app(CurrentRate::class)->snapshot()?->rate);
    }

    /**
     * **Una resolución por respuesta.** Aunque el histórico cambie a mitad,
     * todo lo que pregunte en esa petición lee lo mismo.
     */
    public function test_it_resolves_only_once(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $rate = app(CurrentRate::class);
        $first = $rate->snapshot()?->rate;

        // El job de ingesta inserta a mitad de la petición.
        $this->persistRate('90.00000000', CarbonImmutable::now());

        $this->assertSame($first, $rate->snapshot()?->rate);
        $this->assertSame(42.5, $rate->view()->rate);
    }

    /**
     * Y el sello sale del mismo sitio que la proyección: no pueden
     * contradecirse.
     */
    public function test_the_view_comes_from_the_same_snapshot(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $rate = app(CurrentRate::class);

        $this->assertSame(42.5, $rate->view()->rate);
        $this->assertSame('42.50000000', $rate->snapshot()?->rate);
    }

    /**
     * `null` no es un error: es el estado degradado de `REQ-FX-005` RB-4, en
     * el que la tienda sigue vendiendo en dólares.
     */
    public function test_without_any_rate_it_answers_null_without_failing(): void
    {
        app(TenantContext::class)->setOrganization($this->organization);

        $rate = app(CurrentRate::class);

        $this->assertNull($rate->snapshot());
        $this->assertNull($rate->view()->rate);
        $this->assertFalse($rate->view()->isStale);
    }

    /**
     * Fuera de un tenant —marketing, acceso, feed— no hay negocio del que
     * tomar la fuente, y eso se contesta con la de plataforma en lugar de
     * dejar que el contexto lance.
     */
    public function test_outside_a_tenant_it_answers_with_the_platform_source(): void
    {
        app(TenantContext::class)->forget();

        $view = app(CurrentRate::class)->view();

        $this->assertSame(RateSource::Bcv, $view->source);
        $this->assertNull($view->rate);
    }

    /**
     * Resuelve con **la fuente que eligió esa organización**, no con la
     * primera que haya en la tabla (`REQ-FX-003` RB-1).
     */
    public function test_it_uses_the_source_the_organization_chose(): void
    {
        $this->persistRate('36.50000000', source: RateSource::Bcv);
        $this->persistRate('42.20000000', source: RateSource::Parallel);

        $this->withinOrganization($this->organization, function (): void {
            OrganizationSetting::query()->updateOrCreate(
                ['key' => SettingKey::ExchangeRateSource->value],
                ['value' => RateSource::Parallel->value],
            );
        });

        app(\App\Support\Settings::class)->forget($this->organization);
        app(TenantContext::class)->setOrganization($this->organization);

        $this->assertSame('42.20000000', app(CurrentRate::class)->snapshot()?->rate);
        $this->assertSame(RateSource::Parallel, app(CurrentRate::class)->view()->source);
    }

    /**
     * **El memo es `scoped()`, no `singleton()`.** Tiene que morir con la
     * petición: un singleton sobreviviría en el worker de colas —un proceso de
     * vida larga— y serviría la tasa de hace media hora en el job siguiente,
     * que es la trampa que `REQ-FX-003` RB-5b dejó escrita.
     */
    public function test_the_memo_dies_with_the_request(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $this->assertSame('42.50000000', app(CurrentRate::class)->snapshot()?->rate);

        $this->persistRate('50.00000000', CarbonImmutable::now());

        // Lo que Laravel hace entre peticiones y entre jobs.
        app()->forgetScopedInstances();

        // Sin `?->`: la aserción de más arriba ya prueba que hay tasa, y
        // PHPStan lo sabe.
        $this->assertSame('50.00000000', app(CurrentRate::class)->snapshot()->rate);
    }

    private function persistRate(
        string $rate,
        ?CarbonImmutable $publishedAt = null,
        RateSource $source = RateSource::Bcv,
    ): void {
        ExchangeRate::query()->create([
            'source' => $source,
            'rate' => $rate,
            'published_at' => $publishedAt ?? CarbonImmutable::now()->subHour(),
        ]);

        Cache::flush();
    }
}
