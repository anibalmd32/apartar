<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationNotActiveException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Lo que decide la cadena de middlewares del backoffice (`REQ-BOF-001`).
 *
 * Las rutas de negocio —cobranza, comprobantes, productos— llegan con sus
 * hitos, así que los casos que necesitan una las registran aquí, montadas
 * igual que lo estarán ellas. Lo que se prueba es el mecanismo, y el mecanismo
 * es lo que van a heredar.
 */
final class BackofficeAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El happy path de RB-2 y RB-3 del requisito: **el tenant se fija desde la
     * sesión y las consultas quedan filtradas**, con dos organizaciones vivas.
     *
     * La ruta de prueba cuenta usuarios sin escribir un solo `where`. Si el
     * tenant no se fijara, la consulta lanzaría; si se fijara mal, devolvería
     * los tres del vecino. Devuelve dos: los suyos.
     */
    public function test_the_tenant_is_fixed_from_the_session_and_filters_every_query(): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'])
            ->get('__backoffice/equipo', fn (): array => [
                'organization' => app(TenantContext::class)->organizationId(),
                'users' => User::query()->count(),
            ]);

        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $actor = $this->withinOrganization($own, function (): User {
            User::factory()->create();

            return User::factory()->owner()->create();
        });

        $this->withinOrganization($neighbour, fn () => User::factory()->count(3)->create());
        $this->withoutOrganization();

        $this->actingAs($actor)
            ->getJson('/__backoffice/equipo')
            ->assertOk()
            ->assertJson([
                'organization' => $own->getKey(),
                'users' => 2,
            ]);
    }

    /**
     * Y el panel de verdad se renderiza con su miembro en las props
     * compartidas: el rol y el estado del negocio viajan a **todas** las
     * pantallas, que es lo que permite filtrar el menú y avisar de la
     * suspensión sin que cada controlador se acuerde.
     */
    public function test_the_dashboard_renders_with_the_team_member_in_the_shared_props(): void
    {
        $actor = $this->teamMember();

        $this->actingAs($actor)
            ->get('/backoffice')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/Dashboard')
                ->where('teamMember.role', 'owner')
                ->where('teamMember.organization.status', 'active')
            );
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get('/backoffice')->assertRedirect(route('login'));
    }

    /**
     * RB-4 y SP-3 · el desactivado pierde la sesión **en la siguiente
     * petición**, no en el siguiente inicio de sesión.
     */
    public function test_a_member_disabled_mid_session_loses_it_on_the_next_request(): void
    {
        $actor = $this->teamMember();

        $this->signIn($actor);
        $this->get('/backoffice')->assertOk();

        $this->withinOrganization(
            $actor->organization,
            fn () => User::query()->whereKey($actor->getKey())->update(['disabled_at' => now()]),
        );

        $this->nextRequest();

        $this->get('/backoffice')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * RB-5 · con la contraseña pendiente, toda la navegación lleva al mismo
     * sitio. Si se pudiera navegar «solo un poco», la rotación sería opcional.
     */
    public function test_a_pending_password_change_hijacks_every_page(): void
    {
        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->get('/backoffice')->assertRedirect(route('password.change'));

        $this->get('/backoffice/cambiar-contrasena')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/ChangePassword'));
    }

    /**
     * Y al cambiarla, se sigue donde se iba. La contraseña nueva cumple RB-6,
     * la misma regla que el registro.
     */
    public function test_changing_the_password_releases_the_navigation(): void
    {
        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->post('/backoffice/cambiar-contrasena', [
            'password' => 'Zapatos-Del-Valle-2026',
            'password_confirmation' => 'Zapatos-Del-Valle-2026',
        ])->assertRedirect(route('dashboard'));

        $this->get('/backoffice')->assertOk();
    }

    /**
     * SP-7 · una contraseña comprometida se rechaza también aquí.
     */
    public function test_a_compromised_password_is_refused_in_the_forced_change(): void
    {
        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->post('/backoffice/cambiar-contrasena', [
            'password' => 'Password123456',
            'password_confirmation' => 'Password123456',
        ])->assertSessionHasErrors('password');
    }

    /**
     * Salir sigue funcionando con la contraseña pendiente: encerrar a alguien
     * entre dos exigencias que se bloquean sería una trampa.
     */
    public function test_logging_out_still_works_with_a_pending_password_change(): void
    {
        $actor = $this->teamMember(['must_change_password' => true]);

        $this->actingAs($actor)->post('/backoffice/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * RB-10 · si el negocio cierra con la sesión abierta, la siguiente
     * petición la cierra con el motivo.
     */
    public function test_a_closed_organization_ends_the_open_session(): void
    {
        $actor = $this->teamMember();

        $this->signIn($actor);
        $this->get('/backoffice')->assertOk();

        $actor->organization->update(['status' => OrganizationStatus::Closed]);

        $this->nextRequest();

        $this->get('/backoffice')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => OrganizationNotActiveException::CLOSED_MESSAGE]);

        $this->assertGuest();
    }

    /**
     * SP-6 · petición de Inertia con la sesión caducada: `409` con la URL del
     * acceso, para que el cliente **recargue entero** en lugar de quedarse con
     * la pantalla anterior como si no hubiera pasado nada.
     */
    public function test_an_expired_inertia_request_gets_a_409_to_the_login(): void
    {
        // La sesión caduca casi siempre en un envío: el `POST` sale con un
        // token que ya no vale y el servidor responde `419`.
        Route::middleware('web')->post('__backoffice/caducada', fn () => abort(419));

        $response = $this->withHeader('X-Inertia', 'true')->post('/__backoffice/caducada');

        $response->assertStatus(409);
        $this->assertSame(route('login'), $response->headers->get('X-Inertia-Location'));
    }

    /**
     * Y una petición normal con el mismo fallo no se convierte en `409`: el
     * `409` solo tiene sentido para el cliente de Inertia.
     */
    public function test_a_plain_request_keeps_its_own_status(): void
    {
        Route::middleware('web')->post('__backoffice/caducada-normal', fn () => abort(419));

        $this->post('/__backoffice/caducada-normal')->assertStatus(419);
    }

    /**
     * Entra por el formulario, como una persona.
     *
     * `actingAs()` no sirve para los casos que miran **la petición
     * siguiente**: fija el usuario en el guard y no escribe nada en la sesión,
     * así que al vaciar los guards para emular la siguiente petición el
     * usuario desaparecería y el test pasaría por el motivo equivocado —un
     * `302` al login que no lo firma ningún middleware nuestro—.
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
     * vuelve a leer al usuario de `users` y el `TenantContext` nace vacío para
     * que `ResolveTenant` lo llene con la fila de ahora. Dentro de un test las
     * dos peticiones comparten contenedor, así que sin esto se estaría
     * midiendo la memoria del proceso en vez de lo que dicen RB-4 y RB-10.
     */
    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $organizationState
     */
    private function teamMember(array $state = [], array $organizationState = []): User
    {
        $organization = Organization::factory()->create($organizationState);
        $this->neighbourOrganization();

        $user = $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->owner()->create($state),
        );

        $this->withoutOrganization();

        return $user;
    }
}
