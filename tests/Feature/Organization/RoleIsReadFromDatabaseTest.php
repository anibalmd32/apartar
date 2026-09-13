<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `REQ-ORG-003` RB-6 y SP-4: el rol se resuelve **desde la base de datos en
 * cada petición**, nunca desde un claim de la sesión.
 *
 * El motivo es concreto y no es de pureza: con el rol guardado en la sesión,
 * degradar a alguien sería una sugerencia hasta que le apeteciera volver a
 * entrar. A quien se le retira el acceso a la facturación suele ser alguien
 * que ya no debería tenerlo **ahora**, no mañana.
 *
 * Las dos mitades se comprueban por separado y las dos hacen falta: que la
 * sesión no guarde el rol —no hay nada que pueda quedarse rancio— y que el
 * cambio surta efecto en la petición siguiente —se lee de donde toca—.
 */
final class RoleIsReadFromDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SP-4 · ascender con la sesión abierta: el permiso aplica en la siguiente
     * petición, sin volver a entrar.
     */
    public function test_a_promotion_applies_on_the_next_request(): void
    {
        $url = $this->probeRoute();
        $member = $this->seedUser();

        $this->login($member);

        $this->get($url)->assertForbidden();

        $this->writeRole($member, OrganizationRole::Owner);
        $this->nextRequest();

        $this->get($url)->assertOk();
    }

    /**
     * Y la dirección que de verdad importa: degradar corta el acceso en el
     * siguiente clic. Si esta fuese la que falla, una revocación sería
     * decorativa.
     */
    public function test_a_demotion_applies_on_the_next_request(): void
    {
        $url = $this->probeRoute();
        $owner = $this->seedUser(OrganizationRole::Owner);

        $this->login($owner);

        $this->get($url)->assertOk();

        $this->writeRole($owner, OrganizationRole::Member);
        $this->nextRequest();

        $this->get($url)->assertForbidden();
    }

    /**
     * La otra mitad: en la sesión no hay ningún rol que pueda quedarse rancio.
     *
     * Se mira el contenido entero y no una clave concreta, porque el descuido
     * que esto persigue no es "alguien guardó `role`", es "alguien guardó el
     * usuario entero para ahorrarse una consulta".
     */
    public function test_the_session_holds_no_role(): void
    {
        $url = $this->probeRoute();
        $owner = $this->seedUser(OrganizationRole::Owner);

        $this->login($owner);
        $this->get($url)->assertOk();

        $payload = (string) json_encode(session()->all());

        $this->assertStringNotContainsString(OrganizationRole::Owner->value, $payload);
        $this->assertStringNotContainsString(OrganizationRole::Member->value, $payload);
        $this->assertStringNotContainsString($owner->email, $payload);
    }

    /**
     * Lo que la sesión sí guarda es un identificador, que es lo único que
     * puede guardar sin quedarse rancio.
     */
    public function test_the_session_holds_only_the_user_identifier(): void
    {
        $owner = $this->seedUser(OrganizationRole::Owner);

        $this->login($owner);

        $this->assertAuthenticatedAs($owner);

        $stored = array_filter(
            session()->all(),
            fn (mixed $value): bool => $value === $owner->getKey(),
        );

        $this->assertNotEmpty($stored);
    }

    // -------------------------------------------------------------------
    // El escenario
    // -------------------------------------------------------------------

    /**
     * Ruta de prueba cerrada al `owner`. Las de verdad llegan con `T-013`.
     */
    private function probeRoute(): string
    {
        Route::middleware(['web', 'auth', 'tenant'])->get('__roles/equipo', function (): array {
            Gate::authorize('viewAny', User::class);

            return ['ok' => true];
        });

        return '/__roles/equipo';
    }

    private function seedUser(OrganizationRole $role = OrganizationRole::Member): User
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $user = $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->state(['role' => $role])->create(),
        );

        // Un segundo owner, para que degradar al primero no choque con la
        // invariante de RB-2 mientras se prueba otra cosa.
        $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        return $user;
    }

    /**
     * Empieza la sesión como lo hace una persona: por el formulario de acceso.
     *
     * `actingAs()` no serviría para lo que se prueba aquí —fija la instancia
     * del usuario a mano y con ella el rol— y el test pasaría sin tocar la
     * base de datos ni una vez.
     */
    private function login(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();
    }

    /**
     * El cambio de rol lo hace otro `owner`, en otra petición: aquí se escribe
     * directamente en la tabla porque lo que se prueba no es quién asciende
     * —eso es `T-013`— sino desde dónde se lee el rol.
     */
    private function writeRole(User $user, OrganizationRole $role): void
    {
        DB::table('users')->where('id', $user->getKey())->update(['role' => $role->value]);
    }

    /**
     * Emula lo que en producción hace el arranque de la petición siguiente:
     * empezar con los guards sin resolver.
     *
     * En php-fpm cada petición construye un contenedor nuevo y el guard vuelve
     * a buscar al usuario en `users`; dentro de un test, las dos peticiones
     * comparten contenedor y el guard conservaría la instancia que ya
     * resolvió. Es exactamente lo que Octane limpia entre peticiones, y sin
     * esto el test mediría la memoria del proceso en lugar de lo que dice
     * RB-6.
     */
    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
    }
}
