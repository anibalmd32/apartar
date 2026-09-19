<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La fuente de tasa de cada organización (`REQ-FX-003`).
 *
 * **La ingesta es de plataforma; la elección es de cada negocio** (`D-6`). Lo
 * que se prueba aquí es el lado de escritura: quién puede cambiarla, qué
 * estados no pueden existir, y que elegirla no toca nada más.
 */
final class ExchangeRateSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

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
     * SP-3 · solo el `owner`. Es `403` y no `404`: el recurso es de su propia
     * organización, así que no hay nada que ocultar.
     */
    public function test_a_member_cannot_change_the_source(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)
            ->put('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'parallel'])
            ->assertForbidden();

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    public function test_the_owner_changes_the_source(): void
    {
        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'parallel'])
            ->assertRedirect('/backoffice/ajustes');

        $this->assertSame('parallel', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    /**
     * **SP-1 · el estado «fuente manual, tasa nula» no puede existir.**
     *
     * No es una validación de formulario: en ese estado `current()` devuelve
     * `null` y **todos los catálogos de ese negocio se quedan sin bolívares**
     * hasta que alguien vuelva a la pantalla.
     */
    public function test_switching_to_manual_without_a_rate_is_rejected(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'manual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exchange_rate_manual');

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    /**
     * Y tampoco por el camino de enviarla vacía, que es lo que manda un
     * `input` numérico al borrarlo.
     */
    public function test_switching_to_manual_with_an_empty_rate_is_rejected(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/backoffice/ajustes/tasa', [
                'exchange_rate_source' => 'manual',
                'exchange_rate_manual' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exchange_rate_manual');

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    /**
     * SP-2 · formato inválido o ≤ 0 es `422`.
     */
    public function test_an_impossible_manual_rate_is_rejected(): void
    {
        foreach (['0', '-42.5', 'cuarenta'] as $rate) {
            $this->actingAs($this->owner())
                ->putJson('/backoffice/ajustes/tasa', [
                    'exchange_rate_source' => 'manual',
                    'exchange_rate_manual' => $rate,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('exchange_rate_manual');
        }

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    public function test_the_owner_sets_a_manual_rate(): void
    {
        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes/tasa', [
                'exchange_rate_source' => 'manual',
                'exchange_rate_manual' => '55.00',
            ])
            ->assertRedirect('/backoffice/ajustes');

        $this->assertSame('manual', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('55.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    /**
     * **RB-4 · la manual no entra en `exchange_rates`.** Esa tabla es de
     * plataforma y guarda fuentes públicas; la tasa de un negocio es un dato
     * suyo y su histórico vive en la auditoría.
     */
    public function test_a_manual_rate_never_reaches_the_platform_table(): void
    {
        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes/tasa', [
                'exchange_rate_source' => 'manual',
                'exchange_rate_manual' => '55.00',
            ])
            ->assertRedirect('/backoffice/ajustes');

        $this->assertSame(0, ExchangeRate::query()->count());
    }

    /**
     * SP-6 · quien pasa a BCV **conserva** su tasa manual en los ajustes, y la
     * recupera tal cual si vuelve a elegirla. Borrarla obligaría a
     * reescribirla sin que nadie hubiera pedido borrarla.
     */
    public function test_leaving_manual_keeps_the_rate_for_later(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->put('/backoffice/ajustes/tasa', [
            'exchange_rate_source' => 'manual',
            'exchange_rate_manual' => '55.00',
        ]);

        $this->actingAs($owner)->put('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'bcv']);

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('55.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    /**
     * RB-3 · la manual lleva `publishedAt` = `fetchedAt` = fecha de la edición,
     * así que **no nace obsoleta**. Una tasa que el dueño acaba de fijar
     * avisando de que está desactualizada es el aviso que enseña a ignorar
     * todos los avisos.
     */
    public function test_a_freshly_set_manual_rate_is_not_born_stale(): void
    {
        $this->actingAs($this->owner())->put('/backoffice/ajustes/tasa', [
            'exchange_rate_source' => 'manual',
            'exchange_rate_manual' => '55.00',
        ]);

        $snapshot = app(ExchangeRateService::class)->current($this->organization);

        $this->assertNotNull($snapshot);
        $this->assertSame(RateSource::Manual, $snapshot->source);
        $this->assertFalse($snapshot->isStale());
        $this->assertTrue($snapshot->publishedAt->equalTo($snapshot->fetchedAt));
    }

    /**
     * **RB-1, la regla de la tarea.** Dos organizaciones con fuentes distintas
     * proyectan importes distintos para el mismo precio en dólares.
     *
     * El gate lo enuncia sobre «el mismo producto» y los productos llegan en
     * H2, así que se prueba donde la regla de verdad vive: el mismo `Money` en
     * USD, la tasa que resuelve `current()` para cada una, y dos cifras
     * distintas en bolívares. Es la misma afirmación sin catálogo de por
     * medio.
     */
    public function test_two_organizations_with_different_sources_project_different_amounts(): void
    {
        $this->persistRate(RateSource::Bcv, '36.50000000');
        $this->persistRate(RateSource::Parallel, '42.20000000');
        Cache::flush();

        $street = $this->organizationWithSource(RateSource::Parallel);

        $price = new Money(2_000);
        $rates = app(ExchangeRateService::class);

        $conservative = $price->convertTo(Currency::VES, $rates->current($this->organization));
        $aggressive = $price->convertTo(Currency::VES, $rates->current($street));

        $this->assertSame(73_000, $conservative->cents);
        $this->assertSame(84_400, $aggressive->cents);
        $this->assertNotSame($conservative->cents, $aggressive->cents);
    }

    /**
     * Y con la manual, lo mismo: un negocio que fija su propia tasa proyecta
     * con ella y no con ninguna de las públicas.
     */
    public function test_a_manual_organization_projects_with_its_own_rate(): void
    {
        $this->persistRate(RateSource::Bcv, '36.50000000');
        Cache::flush();

        $this->actingAs($this->owner())->put('/backoffice/ajustes/tasa', [
            'exchange_rate_source' => 'manual',
            'exchange_rate_manual' => '50.00',
        ]);

        $snapshot = app(ExchangeRateService::class)->current($this->organization);

        $this->assertNotNull($snapshot);
        $this->assertSame(100_000, (new Money(2_000))->convertTo(Currency::VES, $snapshot)->cents);
    }

    /**
     * SP-4 · elegir una fuente sin tasa reciente **se permite**. Bloquearlo
     * sería violar `REQ-FX-005` RB-1 desde la pantalla de ajustes.
     */
    public function test_choosing_a_source_without_any_rate_is_allowed(): void
    {
        $this->assertSame(0, ExchangeRate::query()->count());

        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'parallel'])
            ->assertRedirect('/backoffice/ajustes');

        $this->assertSame('parallel', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertNull(app(ExchangeRateService::class)->current($this->organization));
    }

    /**
     * RB-6 · la pantalla trae la tasa vigente de cada fuente de plataforma con
     * su fecha de **publicación**, para poder comparar antes de elegir.
     */
    public function test_the_screen_carries_every_platform_source_with_its_rate(): void
    {
        $this->persistRate(RateSource::Bcv, '36.50000000', '2026-09-19 09:00:00');
        $this->persistRate(RateSource::Parallel, '42.20000000', '2026-09-19 09:00:00');
        Cache::flush();

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rateSources', 2)
                ->where('rateSources.0.source', 'bcv')
                ->where('rateSources.0.rate', 36.5)
                ->where('rateSources.1.source', 'parallel')
                ->where('rateSources.1.rate', 42.2)
                ->where('rateSources.0.publishedAt', fn (string $at): bool => str_contains($at, '2026-09-19')));
    }

    /**
     * Y una fuente **sin ninguna tasa** viaja igual, con `rate: null` (SP-4):
     * la pantalla dice que aún no hay cifra en vez de esconder la opción.
     */
    public function test_a_source_without_a_rate_still_travels(): void
    {
        $this->persistRate(RateSource::Bcv, '36.50000000');
        Cache::flush();

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rateSources', 2)
                ->where('rateSources.1.source', 'parallel')
                ->where('rateSources.1.rate', null)
                ->where('rateSources.1.publishedAt', null));
    }

    /**
     * RB-4 · el cambio se audita (`REQ-BOF-005`), con el antes y el después.
     * La entrada es la que responde «quién la cambió y desde qué valor», que
     * es la pregunta que aparece cuando un cliente discute un precio.
     */
    public function test_the_change_is_audited_with_its_before_and_after(): void
    {
        $this->actingAs($this->owner())->put('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'parallel']);

        $entry = $this->withinOrganization(
            $this->organization,
            fn (): ?AuditEntry => AuditEntry::query()->where('event', AuditEvent::SettingsUpdated->value)->latest('id')->first(),
        );

        $this->assertNotNull($entry);

        /** @var array<string, mixed> $properties */
        $properties = $entry->properties->toArray();

        $this->assertSame('bcv', $properties['changed']['exchange_rate_source']['before']);
        $this->assertSame('parallel', $properties['changed']['exchange_rate_source']['after']);
    }

    /**
     * RB-3 de `REQ-ORG-006` · la caché de ajustes se invalida al guardar. Sin
     * esto, quien acaba de elegir el paralelo seguiría viendo el BCV y dudaría
     * de si el formulario funciona.
     */
    public function test_the_settings_cache_is_invalidated(): void
    {
        $rates = app(ExchangeRateService::class);

        $this->assertSame(RateSource::Bcv, $rates->sourceFor($this->organization));

        $this->actingAs($this->owner())->put('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'parallel']);

        $this->assertSame(RateSource::Parallel, app(ExchangeRateService::class)->sourceFor($this->organization));
    }

    /**
     * La ruta de la tasa **no es una puerta trasera a los demás ajustes**: el
     * `FormRequest` se queda con el sobre y lo que no es suyo no llega a la
     * acción.
     */
    public function test_the_rate_route_cannot_change_other_settings(): void
    {
        $this->actingAs($this->owner())->put('/backoffice/ajustes/tasa', [
            'exchange_rate_source' => 'bcv',
            'grace_period_days' => 29,
        ]);

        $this->assertSame(3, $this->settingOf(SettingKey::GracePeriodDays));
    }

    /**
     * **No sobrevive a la suspensión**, y aquí la tentación era real: la tasa
     * se ve en el catálogo. Pero elegir con qué tasa se proyecta es gobierno
     * del negocio, no cobrar ni aprobar comprobantes, que es lo único que
     * `REQ-ORG-008` RB-3 conserva —y la suspendida sigue proyectando con la
     * fuente que ya tenía elegida—.
     *
     * La ruta no declara `during-suspension`, así que queda bloqueada por
     * fallo cerrado; este test fija esa decisión en vez de dejarla al
     * descuido de no haberla declarado.
     */
    public function test_the_route_does_not_survive_a_suspension(): void
    {
        $owner = $this->owner();

        $this->organization->forceFill([
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => CarbonImmutable::now(),
        ])->save();

        $this->actingAs($owner)
            ->put('/backoffice/ajustes/tasa', ['exchange_rate_source' => 'parallel'])
            ->assertForbidden();

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    private function organizationWithSource(RateSource $source): Organization
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, function () use ($source): void {
            OrganizationSetting::query()->create([
                'key' => SettingKey::ExchangeRateSource,
                'value' => $source->value,
                'updated_by' => null,
            ]);
        });

        return $organization;
    }

    private function settingOf(SettingKey $key): mixed
    {
        app(Settings::class)->forget($this->organization);

        return $this->withinOrganization($this->organization, fn (): mixed => app(Settings::class)->get($key));
    }

    private function persistRate(RateSource $source, string $rate, string $publishedAt = '2026-09-19 09:00:00'): void
    {
        ExchangeRate::query()->create([
            'source' => $source,
            'rate' => $rate,
            'published_at' => CarbonImmutable::parse($publishedAt),
        ]);
    }
}
