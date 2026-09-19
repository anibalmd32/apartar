<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Money;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * El único sitio por el que un importe sale al cliente (`REQ-FX-001` RB-6,
 * `REQ-FX-005` RB-4).
 *
 * Lo que se fija aquí son las dos reglas que sostienen toda la presentación
 * del dinero: que el formateo lo hace el servidor con las dos convenciones
 * invertidas, y que **sin tasa el bolívar no aparece de ninguna forma**.
 */
final class ProjectsMoneyTest extends TestCase
{
    use ProjectsMoney;
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

        app(TenantContext::class)->setOrganization($this->organization);
    }

    /**
     * Con tasa, las dos representaciones, **ya formateadas y con la puntuación
     * de cada moneda**: `$1,234.56` lleva coma de miles y punto decimal, y
     * `Bs. 1.234,56` los lleva al revés. Un `.toFixed(2)` en el cliente
     * produciría una cifra que se lee como un precio correcto y no lo es.
     */
    public function test_it_composes_both_representations(): void
    {
        $this->persistRate('42.50000000');

        $this->assertSame(
            ['cents' => 2_000, 'usd' => '$20.00', 'ves' => 'Bs. 850,00'],
            $this->money(new Money(2_000)),
        );
    }

    public function test_it_keeps_the_punctuation_of_each_currency(): void
    {
        $this->persistRate('42.50000000');

        $view = $this->money(new Money(123_456));

        $this->assertSame('$1,234.56', $view['usd']);
        $this->assertSame('Bs. 52.468,80', $view['ves']);
    }

    /**
     * **RB-4 · sin tasa, `ves` es `null`.** Ni `Bs. 0,00` ni un guion ni «no
     * disponible»: las tres cosas ponen en pantalla un precio que nadie ha
     * calculado. La tienda sigue vendiendo en dólares.
     */
    public function test_without_a_rate_the_bolivar_does_not_appear(): void
    {
        $this->assertSame(0, ExchangeRate::query()->count());

        $view = $this->money(new Money(2_000));

        $this->assertNull($view['ves']);
        $this->assertSame('$20.00', $view['usd']);
        $this->assertSame(2_000, $view['cents']);
    }

    public function test_the_missing_bolivar_is_never_a_zero_or_a_dash(): void
    {
        $view = $this->money(new Money(2_000));

        $this->assertNotSame('Bs. 0,00', $view['ves']);
        $this->assertNotSame('—', $view['ves']);
        $this->assertNotSame('', $view['ves']);
    }

    /**
     * **RB-3 · una tasa de treinta horas marca `isStale` y aun así proyecta.**
     * El aviso es discreto, no un bloqueo: dejar de proyectar por antigüedad
     * violaría RB-1, que es la regla que sostiene el requisito entero.
     */
    public function test_a_stale_rate_still_projects(): void
    {
        $this->persistRate('42.50000000', CarbonImmutable::now()->subHours(30));

        $view = $this->money(new Money(2_000));

        $this->assertSame('Bs. 850,00', $view['ves']);
        $this->assertTrue(app(\App\Domain\Exchange\CurrentRate::class)->view()->isStale);
    }

    /**
     * Proyecta con **la fuente que eligió esa organización** (`REQ-FX-003`
     * RB-1), no con la primera que haya en la tabla.
     */
    public function test_it_projects_with_the_source_the_organization_chose(): void
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

        $this->assertSame('Bs. 844,00', $this->money(new Money(2_000))['ves']);
    }

    /**
     * **La tasa se resuelve una vez por respuesta**, y esto es lo que lo
     * comprueba: si el histórico cambia a mitad, los importes ya proyectados y
     * los siguientes siguen hablando de la misma tasa. Sin el memo, una página
     * podría estampar una tasa distinta de la que multiplicó —y el número
     * sería creíble, que es lo que lo hace caro—.
     */
    public function test_every_amount_of_a_response_uses_the_same_rate(): void
    {
        $this->persistRate('42.50000000');

        $first = $this->money(new Money(2_000));

        // El job de ingesta inserta a mitad de la petición.
        $this->persistRate('90.00000000', CarbonImmutable::now()->addMinute());
        Cache::flush();

        $second = $this->money(new Money(2_000));

        $this->assertSame($first['ves'], $second['ves']);
        $this->assertSame('Bs. 850,00', $second['ves']);
    }

    /**
     * Y el sello sale del mismo sitio: la cifra que pinta la página y la tasa
     * que dice haberla producido no pueden contradecirse.
     */
    public function test_the_stamp_and_the_amounts_come_from_the_same_resolution(): void
    {
        $this->persistRate('42.50000000');

        $view = $this->money(new Money(2_000));
        $stamp = app(\App\Domain\Exchange\CurrentRate::class)->view();

        $this->assertSame('Bs. 850,00', $view['ves']);
        $this->assertSame(42.5, $stamp->rate);
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
