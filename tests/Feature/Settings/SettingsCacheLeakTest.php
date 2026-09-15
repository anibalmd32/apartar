<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\ExplodingCacheStore;
use Tests\TestCase;

/**
 * La caché de ajustes no filtra entre organizaciones (`REQ-ORG-006` RB-3).
 *
 * **Esto no es un test de rendimiento.** Los ajustes gobiernan períodos de
 * gracia, ventanas de abono inicial y frecuencias de cuota: una caché
 * compartida por error no da ningún error, calcula **fechas de vencimiento con
 * los plazos de otro negocio**, se las comunica al cliente, y el problema
 * aparece semanas después cuando ese cliente reclama —sin nada en ningún log—.
 *
 * Por eso los casos de abajo **intentan la fuga a propósito** en vez de
 * limitarse a comprobar que dos lecturas difieren. Dos lecturas distintas
 * pasan también con una caché rota que no cachea nada; envenenar la entrada de
 * una organización y exigir que la otra siga viendo lo suyo, no.
 */
final class SettingsCacheLeakTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ferreteria;

    private Organization $panaderia;

    protected function setUp(): void
    {
        parent::setUp();

        // REQ-QAX-003 RB-10 · dos organizaciones, y aquí la regla es el test
        // entero: con una sola no hay fuga que observar.
        $this->ferreteria = $this->seedOrganizationWith(gracePeriodDays: 3);
        $this->panaderia = $this->seedOrganizationWith(gracePeriodDays: 14);

        // Ruta de prueba montada como las de verdad —mismo grupo, mismos
        // middlewares—: lo que se mide es el `Settings` resuelto dentro de una
        // petición real, con su tenant fijado por `ResolveTenant`, y no una
        // llamada directa desde el test.
        Route::middleware(['web', 'auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'])
            ->get('__settings/gracia', fn (): array => [
                'grace' => app(Settings::class)->get(SettingKey::GracePeriodDays),
            ]);
    }

    /**
     * En la **misma petición**, alternando: cada una lee lo suyo.
     *
     * El orden importa —A, B y otra vez A— porque el fallo típico del memo en
     * memoria es quedarse con lo primero que resolvió: con solo dos lecturas,
     * una implementación que cachea globalmente pasaría la primera y fallaría
     * la segunda, y la tercera es la que demuestra que volver no contamina.
     */
    public function test_two_organizations_read_their_own_settings_in_the_same_request(): void
    {
        $settings = $this->settings();

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(14, $settings->forOrganization($this->panaderia)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    /**
     * Y en **peticiones consecutivas**: cada `owner` abre sus ajustes y ve los
     * suyos, con la caché ya caliente de la organización anterior.
     */
    public function test_two_organizations_read_their_own_settings_in_consecutive_requests(): void
    {
        $first = $this->ownerOf($this->ferreteria);
        $second = $this->ownerOf($this->panaderia);

        $this->actingAs($first)
            ->getJson('/__settings/gracia')
            ->assertOk()
            ->assertJson(['grace' => 3]);

        $this->nextRequest();

        $this->actingAs($second)
            ->getJson('/__settings/gracia')
            ->assertOk()
            ->assertJson(['grace' => 14]);

        $this->nextRequest();

        $this->actingAs($first)
            ->getJson('/__settings/gracia')
            ->assertOk()
            ->assertJson(['grace' => 3]);
    }

    /**
     * Emula el arranque de la petición siguiente.
     *
     * En producción cada petición construye un contenedor nuevo y el
     * `TenantContext` nace vacío para que `ResolveTenant` lo llene; en un test
     * las peticiones lo comparten, y sin esto la segunda lanzaría
     * `TenantAlreadySetException` antes de llegar a los ajustes.
     *
     * **Lo que no se rehace es `Settings`**, y es deliberado: su memo por
     * organización sobrevive a las tres peticiones, que es la condición más
     * dura que se le puede poner. Si filtrara, filtraría aquí.
     */
    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }

    /**
     * RB-3 · la clave es `settings:{organization_id}`, y **se comprueba la
     * clave**, no solo el valor.
     *
     * Sin esta aserción, una implementación que cacheara bajo `settings` a
     * secas y acertara por casualidad —porque el memo la tapa— pasaría los
     * casos de arriba.
     */
    public function test_each_organization_gets_its_own_cache_key(): void
    {
        $settings = $this->settings();

        $settings->forOrganization($this->ferreteria);
        $settings->forOrganization($this->panaderia);

        $ours = Settings::cacheKeyFor((int) $this->ferreteria->getKey());
        $theirs = Settings::cacheKeyFor((int) $this->panaderia->getKey());

        $this->assertNotSame($ours, $theirs);
        $this->assertTrue(Cache::has($ours));
        $this->assertTrue(Cache::has($theirs));

        $this->assertSame(3, Cache::get($ours)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(14, Cache::get($theirs)[SettingKey::GracePeriodDays->value]);
    }

    /**
     * **La fuga intentada a mano.** Se envenena la entrada de una organización
     * con un valor imposible y se exige que la otra siga leyendo el suyo.
     *
     * Es el caso que de verdad falsifica: una caché con la clave mal formada
     * —sin el identificador, o con el de la organización equivocada— devolvería
     * aquí el veneno, y ningún otro test de este archivo lo notaría.
     */
    public function test_poisoning_one_organizations_entry_does_not_reach_the_other(): void
    {
        $settings = $this->settings();

        Cache::put(
            Settings::cacheKeyFor((int) $this->ferreteria->getKey()),
            [SettingKey::GracePeriodDays->value => 999],
            60,
        );

        $this->assertSame(14, $settings->forOrganization($this->panaderia)[SettingKey::GracePeriodDays->value]);

        // Y el veneno sí llega a quien le tocaba, que es lo que demuestra que
        // la caché se está leyendo de verdad y el test no pasa por no usarla.
        $this->assertSame(999, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    /**
     * Invalidar una organización no invalida a la vecina (RB-3).
     *
     * Es el otro lado de la misma moneda: una invalidación demasiado ancha no
     * filtra datos, pero convierte cada guardado de un negocio en una purga de
     * la caché de todos los demás.
     */
    public function test_forgetting_one_organization_leaves_the_other_cached(): void
    {
        $settings = $this->settings();

        $settings->forOrganization($this->ferreteria);
        $settings->forOrganization($this->panaderia);

        $settings->forget($this->ferreteria);

        $this->assertFalse(Cache::has(Settings::cacheKeyFor((int) $this->ferreteria->getKey())));
        $this->assertTrue(Cache::has(Settings::cacheKeyFor((int) $this->panaderia->getKey())));
    }

    /**
     * SP-7 · **Redis caído se lee de base de datos.**
     *
     * No es una optimización opcional: es la diferencia entre una caída de
     * Redis que ralentiza el backoffice y una que para el negocio. El almacén
     * lanza en todas sus operaciones y aun así los ajustes se resuelven.
     */
    public function test_a_broken_cache_falls_back_to_the_database(): void
    {
        $store = $this->useExplodingCache();

        $settings = $this->settings();

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(14, $settings->forOrganization($this->panaderia)[SettingKey::GracePeriodDays->value]);

        // Y se intentó de verdad: sin esto, el test pasaría también con un
        // `Settings` que hubiera dejado de usar la caché por completo.
        $this->assertGreaterThan(0, $store->attempts, 'No se llegó a tocar la caché.');
    }

    /**
     * Y la invalidación tampoco propaga el fallo del almacén: guardar con
     * Redis caído tiene que guardar.
     */
    public function test_forgetting_survives_a_broken_cache(): void
    {
        $this->useExplodingCache();

        $settings = $this->settings();

        $settings->forget($this->ferreteria);

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    /**
     * El control que evita que los casos de arriba pasen por el motivo
     * equivocado: con la caché sana, una escritura **se ve** en la lectura
     * siguiente porque la invalidación ocurre con ella.
     */
    public function test_a_written_setting_is_visible_right_after(): void
    {
        $settings = $this->settings();

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);

        $this->withinOrganization($this->ferreteria, function (): void {
            OrganizationSetting::query()
                ->where('key', SettingKey::GracePeriodDays)
                ->update(['value' => json_encode(9, JSON_THROW_ON_ERROR)]);
        });

        $settings->forget($this->ferreteria);

        $this->assertSame(9, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    // -------------------------------------------------------------------

    /**
     * La instancia de la aplicación, que es singleton: el memo por
     * organización vive ahí, y probar con instancias nuevas dejaría sin
     * comprobar justamente la copia que no deja rastro fuera del proceso.
     */
    private function settings(): Settings
    {
        return $this->app->make(Settings::class);
    }

    /**
     * Cambia el almacén por uno que lanza en todo, y devuelve el espía.
     */
    private function useExplodingCache(): ExplodingCacheStore
    {
        $store = new ExplodingCacheStore;

        Cache::extend('exploding', fn () => Cache::repository($store));

        config([
            'cache.stores.exploding' => ['driver' => 'exploding'],
            'cache.default' => 'exploding',
        ]);

        // `Settings` ya recibió el repositorio anterior al construirse, así
        // que se rehace: es lo mismo que pasaría en producción, donde cada
        // petición lo resuelve de nuevo.
        $this->app->forgetInstance(Settings::class);

        $this->assertThrows(fn () => Cache::get('lo que sea'), RuntimeException::class);

        return $store;
    }

    private function seedOrganizationWith(int $gracePeriodDays): Organization
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, function () use ($gracePeriodDays): void {
            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key === SettingKey::GracePeriodDays ? $gracePeriodDays : $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });

        $this->withoutOrganization();

        return $organization;
    }

    private function ownerOf(Organization $organization): User
    {
        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        return $owner;
    }
}
