<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationNotActiveException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La puerta del backoffice (`REQ-BOF-001`).
 *
 * Lo que se comprueba aquí, además de que se entre, es **qué se le dice a
 * quien no entra**. El login de un producto multi-tenant con correo único
 * global es un oráculo si se descuida: un mensaje que distinga «ese correo no
 * existe» de «esa contraseña está mal» revela si una persona trabaja en algún
 * negocio de la plataforma.
 */
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_renders_without_asking_for_an_organization(): void
    {
        $this->get('/backoffice/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/Login'));
    }

    public function test_a_team_member_signs_in_and_lands_in_the_backoffice(): void
    {
        $user = $this->teamMember();

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * RB-2 · el correo identifica a la persona, y la persona trae su negocio.
     * No hay nada que elegir.
     */
    public function test_it_never_asks_which_organization(): void
    {
        $this->get('/backoffice/login')
            ->assertOk()
            ->assertDontSee('organización', escape: false)
            ->assertDontSee('organization_id');
    }

    /**
     * SP-1 · el mismo mensaje para el correo inexistente y para la contraseña
     * equivocada.
     */
    public function test_the_message_is_the_same_whatever_failed(): void
    {
        $user = $this->teamMember();

        // El mismo texto en los dos casos, y se comprueba contra el mensaje
        // exacto: comparar las dos respuestas entre sí pasaría también si
        // ambas dijeran «ese correo no existe».
        $generic = (string) __('auth.failed');

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'otra-cosa-larga-123',
        ])->assertSessionHasErrors(['email' => $generic]);

        $this->flushSession();

        $this->post('/backoffice/login', [
            'email' => 'nadie@ejemplo.test',
            'password' => 'otra-cosa-larga-123',
        ])->assertSessionHasErrors(['email' => $generic]);

        $this->assertGuest();
    }

    /**
     * RB-3 y SP-2 · cinco intentos por (correo + IP) cada quince minutos; el
     * sexto es `429` **con `Retry-After`**, porque un cliente que reintenta
     * necesita saber cuándo puede.
     */
    public function test_the_sixth_attempt_is_throttled_with_retry_after(): void
    {
        $user = $this->teamMember();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/backoffice/login', [
                'email' => $user->email,
                'password' => 'la-equivocada-123',
            ])->assertSessionHasErrors('email');
        }

        $response = $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'la-equivocada-123',
        ]);

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    /**
     * Y el bloqueo es por (correo + IP): el compañero de oficina que escribe
     * bien su contraseña entra, aunque el de al lado la haya fallado cinco
     * veces.
     */
    public function test_the_lockout_does_not_reach_the_person_at_the_next_desk(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        [$locked, $colleague] = $this->withinOrganization($organization, fn (): array => [
            User::factory()->create(['email' => 'bloqueada@ejemplo.test']),
            User::factory()->create(['email' => 'compa@ejemplo.test']),
        ]);
        $this->withoutOrganization();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/backoffice/login', ['email' => $locked->email, 'password' => 'mal-123']);
        }

        $this->flushSession();

        $this->post('/backoffice/login', [
            'email' => $colleague->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    /**
     * RB-8 · el intento fallido se registra con correo e IP, y **nunca** con
     * la contraseña. Es la línea de log que alguien acabará pegando en un
     * ticket.
     */
    public function test_a_failed_attempt_is_logged_without_the_password(): void
    {
        $logger = Log::spy();

        $this->post('/backoffice/login', [
            'email' => 'ana@ejemplo.test',
            'password' => 'secreto-larguisimo-123',
        ]);

        $logger->shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'backoffice.login.failed'
                    && $context['email'] === 'ana@ejemplo.test'
                    && array_key_exists('ip', $context)
                    && ! in_array('secreto-larguisimo-123', $context, true)
                    && ! array_key_exists('password', $context);
            });
    }

    /**
     * RB-4 · quien ya no está en el equipo no entra, aunque su contraseña siga
     * siendo la correcta.
     */
    public function test_a_disabled_member_cannot_sign_in(): void
    {
        $user = $this->teamMember(['disabled_at' => now()]);

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * RB-10 y SP-9 · el negocio cerró: se rechaza **con el motivo**, porque
     * quien lo intenta suele ser un empleado que no se enteró.
     */
    public function test_a_closed_organization_rejects_the_login_with_the_reason(): void
    {
        $user = $this->teamMember(organizationState: ['status' => OrganizationStatus::Closed]);

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => OrganizationNotActiveException::CLOSED_MESSAGE]);

        $this->assertGuest();
    }

    /**
     * SP-8 · la suspendida **sí** entra: su equipo sigue teniendo clientes que
     * le deben dinero.
     */
    public function test_a_suspended_organization_still_lets_its_team_in(): void
    {
        $user = $this->teamMember(organizationState: [
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);

        $this->post('/backoffice/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * SP-5 · un operador de plataforma no entra por esta puerta: el guard
     * consulta `users`, y él vive en `platform_users` (`D-5`).
     */
    public function test_a_platform_user_cannot_sign_in_here(): void
    {
        DB::table('platform_users')->insert([
            'name' => 'Operadora',
            'email' => 'operadora@plataforma.test',
            'password' => Hash::make('ContraseñaLarga123'),
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/backoffice/login', [
            'email' => 'operadora@plataforma.test',
            'password' => 'ContraseñaLarga123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * RB-7 · la sesión se regenera al entrar y se invalida al salir.
     */
    public function test_the_session_is_regenerated_on_the_way_in(): void
    {
        $user = $this->teamMember();

        $this->startSession();
        $before = session()->getId();

        $this->post('/backoffice/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotSame($before, session()->getId(), 'La sesión no se regeneró al autenticar.');
    }

    public function test_logging_out_invalidates_the_session(): void
    {
        $user = $this->teamMember();

        $this->actingAs($user);

        $this->post('/backoffice/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Crea a alguien del equipo con su organización y una vecina sembrada
     * (`REQ-QAX-003` RB-10). La contraseña es la de la factory.
     *
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
