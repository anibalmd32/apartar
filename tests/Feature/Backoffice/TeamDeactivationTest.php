<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Actions\Organization\DisableTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\AdminDisabledException;
use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Dar de baja y volver a dar de alta (`REQ-ORG-005`).
 *
 * La concurrencia del último `owner` no está aquí: la prueba
 * `LastOwnerConcurrencyTest` desde `T-009`, con dos conexiones y sobre filas
 * confirmadas, que es la única forma de ver un bloqueo. Lo que se comprueba
 * aquí es todo lo demás, incluida la regla que más se olvida: **bajar de plan
 * no expulsa a nadie**.
 */
final class TeamDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->plan = Plan::factory()->create(['name' => 'Equipo', 'position' => 1, 'max_users' => 3]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function (): void {
                Subscription::factory()->forPlan($this->plan)->create();

                foreach (UsageMetric::cases() as $metric) {
                    UsageCounter::factory()->forMetric($metric)->create();
                }
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * RB-1 y RB-5 · la baja es **lógica** y libera cupo. La fila se queda
     * donde está: borrarla se llevaría por delante quién aprobó qué.
     */
    public function test_disabling_is_logical_and_frees_a_slot(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/desactivar')
            ->assertRedirect('/backoffice/equipo');

        $member->refresh();

        $this->assertNotNull($member->disabled_at);
        $this->assertDatabaseHas('users', ['id' => $member->getKey(), 'name' => $member->name]);
        $this->assertSame(1, $this->consumption());
    }

    /**
     * RB-2 · **la sesión de quien se desactiva cae en su siguiente petición**,
     * no en su siguiente inicio de sesión. Quien acaba de dejar el equipo
     * tiene la pestaña abierta, y es exactamente entonces cuando importa.
     */
    public function test_a_disabled_members_session_drops_on_the_next_request(): void
    {
        [$owner, $member] = $this->team();

        // Se entra **por el formulario** y no con `actingAs`: la garantía de
        // RB-2 es que el guard vuelve a leer la fila en cada petición, y
        // `actingAs` inyecta el objeto en memoria, que nunca se entera de que
        // lo desactivaron.
        $this->post('/backoffice/login', ['email' => $member->email, 'password' => 'password']);

        $this->get('/backoffice')->assertOk();

        // La baja la ordena otro, sin tocar esta sesión. Va por la acción
        // —y no por un `UPDATE` como en `BackofficeAccessTest`, que prueba el
        // middleware— porque lo que se comprueba aquí es que la baja de
        // `REQ-ORG-005` es la que corta el acceso.
        $this->actingAsOrganization($this->organization);
        app(DisableTeamMemberAction::class)->handle($member, $owner);
        $this->nextRequest();

        $this->get('/backoffice')
            ->assertRedirect('/backoffice/login')
            ->assertSessionHasErrors(['email' => AdminDisabledException::MESSAGE]);

        $this->assertGuest();
    }

    /**
     * Emula el arranque de la petición siguiente.
     *
     * En producción cada petición construye un contenedor nuevo: el guard
     * vuelve a leer al usuario de `users` y el `TenantContext` nace vacío. En
     * un test las dos peticiones comparten contenedor, así que sin esto se
     * estaría midiendo la memoria del proceso en vez de lo que dice RB-2.
     */
    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }

    /**
     * RB-7 · las referencias históricas conservan el nombre **con la marca
     * "inactivo"**. Hoy la única referencia que existe es el propio listado del
     * equipo —`reviewed_by` y `registered_by` llegan con `H4`—, y ahí se ve lo
     * que importa: la persona sigue nombrada y marcada, no desaparecida.
     */
    public function test_a_disabled_member_keeps_its_name_marked_as_inactive(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)->post('/backoffice/equipo/'.$member->getKey().'/desactivar');

        $this->actingAs($owner)
            ->get('/backoffice/equipo')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('members', 2)
                ->where('members.1.name', $member->name)
                ->where('members.1.isActive', false)
                ->where('members.0.isActive', true));
    }

    /**
     * SP-1 · nadie se desactiva a sí mismo. Es `422` y no `403`: quien lo
     * intenta es el `owner`, que lo puede todo.
     */
    public function test_nobody_disables_themselves(): void
    {
        [$owner] = $this->team();

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$owner->getKey().'/desactivar')
            ->assertStatus(422);

        $this->assertNull($owner->refresh()->disabled_at);
    }

    /**
     * SP-2 · el último `owner` activo está protegido, y lo hace `LastOwnerGuard`
     * con su bloqueo, no una comprobación escrita aquí.
     *
     * **Por la web este caso no se alcanza en un solo hilo**, y merece la pena
     * verlo: para desactivar a alguien hay que ser `owner`, y si el sujeto es
     * el último `owner` activo, entonces quien lo intenta o es él mismo —y
     * salta antes RB-4— o no es `owner` —y salta la policy—. Queda la vía
     * concurrente, que es SP-3 y que prueba `LastOwnerConcurrencyTest`, y la
     * vía de abajo: un `owner` **ya desactivado** conserva su rol y sigue
     * pasando la policy. Es el escenario que deja la organización sin nadie al
     * mando, y es el que la guarda corta.
     */
    public function test_the_last_active_owner_is_protected_by_the_guard(): void
    {
        [$first] = $this->team();

        $second = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        // Con dos owners, uno se puede ir.
        $this->actingAs($first)
            ->post('/backoffice/equipo/'.$second->getKey().'/desactivar')
            ->assertRedirect('/backoffice/equipo');

        $this->assertSame(1, $this->activeOwners());

        // Y el que queda no: lo pide el que acaba de salir, que sigue siendo
        // `owner` a ojos de la policy.
        $this->actingAsOrganization($this->organization);

        try {
            app(DisableTeamMemberAction::class)->handle($first, $second->refresh());
            $this->fail('Desactivar al último owner debió fallar.');
        } catch (LastOwnerException $exception) {
            $this->assertStringContainsString('último owner', $exception->getMessage());
        }

        $this->assertSame(1, $this->activeOwners());
        $this->assertNull($first->refresh()->disabled_at);
    }

    /**
     * Y la otra mitad de lo anterior: **por HTTP el último `owner` no tiene
     * forma de desactivarse**, porque la comprobación de RB-4 llega antes.
     */
    public function test_the_last_owner_cannot_walk_out_through_the_web(): void
    {
        [$first] = $this->team();

        $this->actingAs($first)
            ->postJson('/backoffice/equipo/'.$first->getKey().'/desactivar')
            ->assertStatus(422);

        $this->assertSame(1, $this->activeOwners());
    }

    /**
     * SP-4 y RB-6 · reactivar **consume cupo igual que invitar** y **fuerza el
     * cambio de contraseña**. Lo primero impide rebasar cualquier plan bajando
     * y subiendo a la gente; lo segundo es precaución: entre la baja y el alta
     * puede haber pasado cualquier cosa con esa contraseña.
     */
    public function test_reenabling_consumes_a_slot_and_forces_a_password_change(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)->post('/backoffice/equipo/'.$member->getKey().'/desactivar');

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/activar')
            ->assertRedirect('/backoffice/equipo');

        $member->refresh();

        $this->assertNull($member->disabled_at);
        $this->assertTrue($member->must_change_password);
        $this->assertSame(2, $this->consumption());
    }

    /**
     * SP-4 · y con el plan lleno, reactivar se rechaza con el `422` del
     * límite, no con un error genérico.
     */
    public function test_reenabling_into_a_full_plan_is_rejected(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)->post('/backoffice/equipo/'.$member->getKey().'/desactivar');

        // Se llenan los tres sitios con gente nueva.
        $this->withinOrganization($this->organization, fn () => User::factory()->count(2)->create());

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$member->getKey().'/activar')
            ->assertStatus(422)
            ->assertJsonPath('limit.metric', 'users');

        $this->assertNotNull($member->refresh()->disabled_at);
    }

    /**
     * SP-7 de `REQ-ORG-004` y RB-4 de `REQ-SUB-008`: **bajar de plan no
     * expulsa a nadie**. Los que están se quedan activos y lo único que se
     * bloquea es invitar al siguiente.
     *
     * Es una decisión de producto deliberada: lo contrario convertiría un
     * cambio de plan en una pérdida de accesos que nadie esperaba.
     */
    public function test_downgrading_keeps_every_member_and_only_blocks_new_ones(): void
    {
        [$owner] = $this->team();

        $this->withinOrganization($this->organization, fn () => User::factory()->create());

        $small = Plan::factory()->create(['name' => 'Pequeño', 'position' => 0, 'max_users' => 1]);

        $this->withinOrganization(
            $this->organization,
            fn () => Subscription::query()->firstOrFail()->forceFill(['plan_id' => $small->getKey()])->save(),
        );

        // Los tres siguen dentro y activos.
        $this->assertSame(3, $this->withinOrganization(
            $this->organization,
            fn (): int => (int) User::query()->whereNull('disabled_at')->count(),
        ));

        $this->actingAs($owner)->get('/backoffice/equipo')->assertOk();

        // Y lo único bloqueado es el cuarto.
        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('limit.used', 3)
            ->assertJsonPath('limit.limit', 1);
    }

    /**
     * SP-5 · quien fue desactivado no entra, y el mensaje no revela que lo
     * desactivaron a él en concreto... salvo después de demostrar que es quien
     * dice ser, que es lo que ya hace `LoginRequest` desde `T-012`. Aquí lo
     * que se comprueba es que la baja lógica es lo que lo deja fuera.
     */
    public function test_a_disabled_member_cannot_log_in(): void
    {
        [$owner, $member] = $this->team();

        // La baja se ordena por la acción y no por la ruta: este caso necesita
        // después una petición **sin sesión**, la de quien intenta entrar, y un
        // `actingAs` previo la contaminaría.
        $this->actingAsOrganization($this->organization);
        app(DisableTeamMemberAction::class)->handle($member, $owner);
        $this->withoutOrganization();

        $this->post('/backoffice/login', ['email' => $member->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // -------------------------------------------------------------------

    /**
     * Un `owner` y un `member`, dentro de la organización del escenario.
     *
     * @return array{0: User, 1: User}
     */
    private function team(): array
    {
        // Nombres fijos y en orden: el listado ordena por nombre, así que un
        // test que mire `members.1` tiene que saber quién es.
        /** @var array{0: User, 1: User} $team */
        $team = $this->withinOrganization($this->organization, fn (): array => [
            User::factory()->owner()->create(['name' => 'Ana Dueña']),
            User::factory()->create(['name' => 'Zoe Empleada']),
        ]);

        $this->withoutOrganization();

        return $team;
    }

    private function consumption(): int
    {
        return $this->withinOrganization(
            $this->organization,
            fn (): int => (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'),
        );
    }

    private function activeOwners(): int
    {
        return $this->withinOrganization($this->organization, fn (): int => (int) User::query()
            ->where('role', 'owner')
            ->whereNull('disabled_at')
            ->count());
    }
}
