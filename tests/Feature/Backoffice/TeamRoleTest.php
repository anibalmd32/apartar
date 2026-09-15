<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promover y degradar, por la vía del producto (`REQ-ORG-003` RB-3, RB-6,
 * SP-2 y SP-4).
 *
 * La concurrencia de SP-3 no está aquí: la prueba `LastOwnerConcurrencyTest`
 * desde `T-009`, con dos conexiones y sobre filas confirmadas, y esta tarea no
 * añade un segundo bloqueo sino que usa el que ya existe.
 */
final class TeamRoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_users' => 10]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();
                UsageCounter::factory()->forMetric(UsageMetric::Users)->create();
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * RB-3 · el `owner` promueve a un `member`.
     */
    public function test_the_owner_promotes_a_member(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'owner'])
            ->assertRedirect('/backoffice/equipo');

        $this->assertSame(OrganizationRole::Owner, $member->refresh()->role);
    }

    /**
     * RB-3 · y **puede degradarse a sí mismo**, que no es el caso de
     * `REQ-ORG-005` RB-4: aquella regla prohíbe *desactivarse*, que es
     * quedarse fuera; degradarse es seguir dentro con menos.
     */
    public function test_an_owner_may_demote_themselves_while_another_remains(): void
    {
        [$owner] = $this->team();

        $second = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$owner->getKey().'/rol', ['role' => 'member'])
            ->assertRedirect('/backoffice/equipo');

        $this->assertSame(OrganizationRole::Member, $owner->refresh()->role);
        $this->assertSame(OrganizationRole::Owner, $second->refresh()->role);
    }

    /**
     * SP-2 · el último `owner` activo no se degrada, y el motivo se dice.
     * Sin la guarda, la organización quedaría sin nadie capaz de invitar, de
     * ascender a otro ni de tocar la suscripción, y de ahí no se sale sin
     * tocar la base de datos a mano.
     */
    public function test_the_last_active_owner_cannot_demote_themselves(): void
    {
        [$owner] = $this->team();

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$owner->getKey().'/rol', ['role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', fn (string $message): bool => str_contains($message, 'último owner'));

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    /**
     * Y el `owner` desactivado no cuenta para eso: si el único que queda
     * activo se degradara, la organización se quedaría igual de sola.
     */
    public function test_a_disabled_owner_does_not_hold_the_seat(): void
    {
        [$owner] = $this->team();

        $this->withinOrganization($this->organization, fn () => User::factory()->owner()->disabled()->create());

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$owner->getKey().'/rol', ['role' => 'member'])
            ->assertStatus(422);

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    /**
     * RB-4 y SP-1 · un `member` llamando a la ruta recibe `403`. Ocultar el
     * selector es cortesía; esto es el control de acceso.
     */
    public function test_a_member_calling_the_route_is_forbidden(): void
    {
        [, $member] = $this->team();

        $this->actingAs($member)
            ->post('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'owner'])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Member, $member->refresh()->role);
    }

    /**
     * SP-4 **por HTTP**: el `member` promovido **con la sesión abierta** tiene
     * el permiso en su siguiente petición.
     *
     * El mecanismo del test importa tanto como el resultado. El `member` entra
     * **por el formulario**, así que su sesión guarda su identificador y nada
     * más; la promoción va **por la ruta nueva**, no por un `update()` —eso
     * probaría RB-6, que ya está probado, y no la vía del producto—; y entre
     * medias no se vuelve a autenticar a nadie: `actingAs($owner)` fija el
     * usuario en el guard sin tocar la sesión, así que al olvidar los guards
     * la petición siguiente se resuelve otra vez desde la sesión del `member`,
     * que sigue intacta.
     *
     * Lo que queda probado es RB-6 aplicado a la vía real: el rol se lee de la
     * base de datos en cada petición y no de un claim de sesión.
     */
    public function test_a_promoted_member_has_the_permission_on_the_next_request(): void
    {
        [$owner, $member] = $this->team();

        $this->signIn($member);

        $this->get('/backoffice/equipo')->assertForbidden();

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'owner'])
            ->assertRedirect('/backoffice/equipo');

        $this->nextRequest();

        $this->get('/backoffice/equipo')->assertOk();
        $this->assertAuthenticatedAs($member->refresh());
    }

    /**
     * Y su simétrica, que es la que de verdad importa: al degradado se le
     * retira el permiso **en el siguiente clic**. Si tardara hasta que volviera
     * a iniciar sesión, una revocación sería una sugerencia.
     */
    public function test_a_demoted_owner_loses_the_permission_on_the_next_request(): void
    {
        [$first] = $this->team();

        $second = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->signIn($second);

        $this->get('/backoffice/equipo')->assertOk();

        $this->actingAs($first)
            ->post('/backoffice/equipo/'.$second->getKey().'/rol', ['role' => 'member'])
            ->assertRedirect('/backoffice/equipo');

        $this->nextRequest();

        $this->get('/backoffice/equipo')->assertForbidden();
        $this->assertAuthenticatedAs($second->refresh());
    }

    /**
     * `REQ-ORG-002` RB-6 · alguien de otra organización da `404` y no `403`:
     * no se confirma que exista.
     */
    public function test_a_member_of_another_organization_is_not_found(): void
    {
        [$owner] = $this->team();

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(),
        );

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$theirs->getKey().'/rol', ['role' => 'owner'])
            ->assertNotFound();

        $this->assertSame(OrganizationRole::Member, $theirs->refresh()->role);
    }

    /**
     * `D-4` deja la lista de roles en dos y la deja cerrada: un valor que no
     * está en el enum no llega a la columna.
     */
    public function test_an_unknown_role_is_rejected(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'superadmin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(OrganizationRole::Member, $member->refresh()->role);
    }

    /**
     * `REQ-ORG-008` RB-3 · repartir el gobierno del negocio no es cobrar ni
     * aprobar comprobantes, así que la ruta no sobrevive a la suspensión. Como
     * las demás de equipo, la omisión del marcador **es** la declaración.
     */
    public function test_the_route_does_not_survive_a_suspension(): void
    {
        [$owner, $member] = $this->team();

        $this->organization->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'owner'])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Member, $member->refresh()->role);
    }

    // -------------------------------------------------------------------

    /**
     * Un `owner` y un `member` en la organización del escenario.
     *
     * @return array{0: User, 1: User}
     */
    private function team(): array
    {
        /** @var array{0: User, 1: User} $team */
        $team = $this->withinOrganization($this->organization, fn (): array => [
            User::factory()->owner()->create(),
            User::factory()->create(),
        ]);

        $this->withoutOrganization();

        return $team;
    }

    /**
     * Entra **por el formulario**, no con `actingAs`.
     *
     * La diferencia es la que hace que SP-4 signifique algo: así la sesión
     * guarda el identificador y el guard vuelve a leer la fila, mientras que
     * `actingAs` inyecta el objeto en memoria y nunca se entera de un cambio
     * de rol.
     */
    private function signIn(User $user): void
    {
        $this->post('/backoffice/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    /**
     * Emula el arranque de la petición siguiente.
     *
     * En producción cada petición construye un contenedor nuevo: el guard
     * vuelve a leer al usuario de `users` y el `TenantContext` nace vacío. En
     * un test las dos peticiones comparten contenedor, así que sin esto se
     * estaría midiendo la memoria del proceso en vez de lo que dice RB-6.
     */
    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }
}
