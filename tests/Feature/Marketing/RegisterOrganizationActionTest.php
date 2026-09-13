<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Actions\Organization\RegisterOrganizationAction;
use App\Actions\Organization\RegisterOrganizationData;
use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\MissingFreePlanException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Las cuatro creaciones del registro (`REQ-ORG-001` RB-1).
 *
 * La atomicidad es lo que de verdad hay que acertar aquí, y por eso hay dos
 * pruebas de rollback y no una: los dos estados que la transacción impide
 * —organización sin suscripción y `owner` huérfano— son estados de los que el
 * sistema no sabe salir, no errores que alguien pueda reintentar.
 */
final class RegisterOrganizationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_it_creates_the_organization_its_owner_its_subscription_and_its_settings(): void
    {
        $organization = $this->register();

        $this->assertSame('Ferretería El Tornillo', $organization->name);
        $this->assertSame('ferreteria-el-tornillo', $organization->slug);
        $this->assertSame(OrganizationStatus::Active, $organization->status);
        $this->assertNull($organization->document, 'El RIF es opcional en el registro (RB-7).');

        $this->withinOrganization($organization, function (): void {
            $owner = User::query()->sole();

            $this->assertSame(OrganizationRole::Owner, $owner->role);
            $this->assertSame('ana@ejemplo.test', $owner->email);
            $this->assertNull($owner->email_verified_at, 'El correo nace sin verificar (RB-5).');

            $subscription = Subscription::query()->sole();

            $this->assertSame(SubscriptionStatus::Active, $subscription->status);
            $this->assertNull($subscription->current_period_end);

            $this->assertSame(11, OrganizationSetting::query()->count());
        });
    }

    /**
     * RB-6 · el plan inicial es siempre Free, sin prueba de plan superior.
     */
    public function test_the_initial_plan_is_free(): void
    {
        $organization = $this->register();

        $this->withinOrganization($organization, function (): void {
            $this->assertSame(
                StartFreeSubscriptionAction::FREE_PLAN_CODE,
                Subscription::query()->sole()->plan->code,
            );
        });
    }

    /**
     * Los once ajustes quedan sembrados con el valor de [02 §9.1], y con su
     * tipo: un `false` que volviera como `"0"` rompería a quien lo lea.
     */
    public function test_it_seeds_the_eleven_default_settings(): void
    {
        $organization = $this->register();

        $this->withinOrganization($organization, function (): void {
            $settings = OrganizationSetting::query()->get()->keyBy(
                fn (OrganizationSetting $setting): string => $setting->key->value,
            );

            $this->assertCount(11, $settings);

            foreach (SettingKey::cases() as $key) {
                $this->assertSame($key->defaultValue(), $settings[$key->value]?->value, sprintf(
                    'El ajuste %s no nació con su valor por defecto.',
                    $key->value,
                ));
            }

            $this->assertNull($settings['grace_period_days']?->updated_by);
        });
    }

    /**
     * Y los contadores de consumo nacen en cero, porque el alta de la
     * suscripción es parte de la misma transacción (`REQ-SUB-002` RB-5).
     */
    public function test_it_starts_the_usage_counters_at_zero(): void
    {
        $organization = $this->register();

        $this->withinOrganization($organization, function (): void {
            $this->assertSame(6, UsageCounter::query()->count());
            $this->assertSame(0, UsageCounter::query()->sum('value'));
        });
    }

    /**
     * La sesión queda iniciada en el guard `web`, y **después** del commit: una
     * sesión que apuntara a filas deshechas sería peor que ninguna.
     */
    public function test_it_signs_the_owner_in(): void
    {
        $organization = $this->register();

        $this->assertTrue(Auth::guard('web')->check());

        $owner = $this->withinOrganization($organization, fn (): User => User::query()->sole());

        $this->assertSame($owner->getKey(), Auth::guard('web')->id());
    }

    /**
     * SP-5 · **rollback total**, haciendo fallar la cuarta creación.
     *
     * Se inyecta el fallo en el primer ajuste, que es lo último que se
     * escribe: para entonces ya existen la organización, el `owner`, la
     * suscripción y los contadores dentro de la transacción. Si el rollback no
     * fuera completo, esto dejaría exactamente el estado que RB-1 prohíbe.
     */
    public function test_a_failure_in_the_fourth_creation_leaves_nothing_behind(): void
    {
        OrganizationSetting::creating(function (): void {
            throw new RuntimeException('fallo al sembrar los ajustes');
        });

        try {
            $this->register();

            $this->fail('Se esperaba que el registro fallara.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fallo al sembrar los ajustes', $exception->getMessage());
        }

        $this->assertNothingWasCreated();
        $this->assertFalse(Auth::guard('web')->check(), 'Quedó una sesión de un registro deshecho.');
    }

    /**
     * SP-1 de `REQ-SUB-002` visto desde aquí: sin plan Free, la tercera
     * creación no puede ocurrir y el registro entero se deshace. Es el mismo
     * rollback, por un camino que sí se da en producción —una base mal
     * sembrada— y sin inyectar nada.
     */
    public function test_without_the_free_plan_the_registration_rolls_back(): void
    {
        Plan::query()->where('code', StartFreeSubscriptionAction::FREE_PLAN_CODE)->delete();

        $this->expectException(MissingFreePlanException::class);

        try {
            $this->register();
        } finally {
            $this->assertNothingWasCreated();
        }
    }

    /**
     * SP-1 · el slug ocupado no se rechaza a secas: se devuelve con una
     * variante libre.
     */
    public function test_a_taken_slug_comes_back_with_a_free_variant(): void
    {
        $this->register();

        try {
            $this->register(email: 'otra@ejemplo.test');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertSame('ferreteria-el-tornillo', $exception->slug);
            $this->assertSame('ferreteria-el-tornillo-2', $exception->suggestion);
        }

        $this->assertSame(1, Organization::query()->count());
    }

    /**
     * Y la variante sugerida está libre de verdad: con `-2` ya tomado, la
     * siguiente es `-3` y no otra vez `-2`.
     */
    public function test_the_suggestion_skips_the_variants_already_taken(): void
    {
        $this->register();
        Organization::factory()->create(['slug' => 'ferreteria-el-tornillo-2']);

        try {
            $this->register(email: 'otra@ejemplo.test');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertSame('ferreteria-el-tornillo-3', $exception->suggestion);
        }
    }

    /**
     * Un nombre que produce un slug reservado tampoco pasa —su vitrina
     * quedaría tapada por una ruta de la plataforma— y se resuelve igual: con
     * una variante libre.
     */
    public function test_a_name_that_slugifies_to_a_reserved_word_is_refused_with_a_variant(): void
    {
        try {
            $this->register(name: 'Precios');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertStringContainsString('reservado', $exception->getMessage());
            $this->assertSame('precios-2', $exception->suggestion);
        }

        $this->assertNothingWasCreated();
    }

    /**
     * SP-7 · un nombre que no produce enlace exige uno escrito a mano. La
     * acción no inventa: una URL que no se parece al negocio es una URL que su
     * dueño no sabe explicar.
     */
    public function test_a_name_without_a_slug_refuses_to_invent_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->register(name: '🎉🎉🎉');
        } finally {
            $this->assertNothingWasCreated();
        }
    }

    /**
     * La carrera: dos registros piden el mismo enlace a la vez y los dos pasan
     * la comprobación previa.
     *
     * Se simula insertando la fila rival **entre** esa comprobación y el
     * `INSERT`, que es exactamente la ventana que deja cualquier `unique:` de
     * validación. Quien pierde no recibe un error de base de datos con el
     * nombre de un índice dentro: recibe la misma sugerencia que habría
     * recibido un segundo antes.
     */
    public function test_a_slug_taken_mid_flight_still_comes_back_with_a_variant(): void
    {
        $rivalCreated = false;

        Organization::creating(function () use (&$rivalCreated): void {
            if ($rivalCreated) {
                return;
            }

            $rivalCreated = true;

            // Sin eventos de modelo: es «otra petición», no esta.
            DB::table('organizations')->insert([
                'name' => 'La que se adelantó',
                'slug' => 'ferreteria-el-tornillo',
                'timezone' => 'America/Caracas',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->register();

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertSame('ferreteria-el-tornillo-2', $exception->suggestion);
        }

        // El registro perdedor no dejó nada. La fila rival tampoco sobrevive,
        // pero eso es de la simulación y no del sistema: al insertarla dentro
        // de la transacción que acaba de deshacerse, se va con ella. En
        // producción la escribe otra petición que ya confirmó.
        $this->assertNothingWasCreated();
    }

    /**
     * Y cuando las variantes numéricas razonables están todas tomadas, el
     * sufijo pasa a ser aleatorio en lugar de que el bucle decida cuándo
     * pararse.
     */
    public function test_it_falls_back_to_a_random_suffix_when_every_variant_is_taken(): void
    {
        Organization::factory()->create(['slug' => 'tienda']);

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            Organization::factory()->create(['slug' => 'tienda-'.$suffix]);
        }

        try {
            $this->register(name: 'Tienda');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertMatchesRegularExpression('/^tienda-\d{4}$/', $exception->suggestion);
        }
    }

    /**
     * El slug escrito a mano manda sobre el derivado del nombre (RB-2).
     */
    public function test_a_hand_written_slug_wins_over_the_name(): void
    {
        $organization = $this->register(slug: 'el-tornillo');

        $this->assertSame('el-tornillo', $organization->slug);
    }

    /**
     * La acción no deja el contexto de tenant tocado: quien la llame dentro de
     * otra petición sigue donde estaba.
     */
    public function test_it_leaves_the_tenant_context_as_it_found_it(): void
    {
        $this->withoutOrganization();

        $this->register();

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    private function register(
        string $name = 'Ferretería El Tornillo',
        string $slug = '',
        string $email = 'ana@ejemplo.test',
    ): Organization {
        return app(RegisterOrganizationAction::class)->handle(new RegisterOrganizationData(
            organizationName: $name,
            organizationSlug: $slug,
            ownerName: 'Ana Pérez',
            ownerEmail: $email,
            ownerPassword: 'ContraseñaLarga123',
        ));
    }

    /**
     * Ni organización, ni usuario, ni suscripción, ni ajustes: las tablas del
     * tenant se miran sin contexto porque no hay ninguna organización viva de
     * la que colgar.
     */
    private function assertNothingWasCreated(): void
    {
        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('subscriptions')->count());
        $this->assertSame(0, DB::table('organization_settings')->count());
        $this->assertSame(0, DB::table('usage_counters')->count());
    }
}
