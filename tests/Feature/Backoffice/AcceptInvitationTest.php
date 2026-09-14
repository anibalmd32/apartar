<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Actions\Organization\InviteTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TeamInvitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El recorrido del enlace de invitación (`REQ-ORG-004` RB-4, RB-5, SP-3,
 * SP-5 y SP-6).
 *
 * El enlace es una credencial y se comprueba como tal: que vale 48 horas, que
 * sirve una sola vez, que el reenvío mata al anterior y que el de una
 * organización no abre la puerta de otra.
 */
final class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_users' => 10]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();

                foreach (UsageMetric::cases() as $metric) {
                    UsageCounter::factory()->forMetric($metric)->create();
                }
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * RB-5 · el happy path: elige su contraseña, queda activo y entra.
     */
    public function test_accepting_sets_the_password_and_lets_the_person_in(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->get('/invitacion/'.$invitation->plainToken)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/auth/AcceptInvitation')
                ->where('email', $invited->email));

        $this->post('/invitacion/'.$invitation->plainToken, [
            'password' => 'Contrasena-Larga-9',
            'password_confirmation' => 'Contrasena-Larga-9',
        ])->assertRedirect('/backoffice');

        $invited->refresh();

        $this->assertNotNull($invited->password);
        $this->assertNull($invited->invitation_token_hash);
        $this->assertFalse($invited->must_change_password);
        $this->assertNotNull($invited->invited_at, 'Cuándo se le invitó es parte de su ficha, no del enlace.');
        $this->assertAuthenticatedAs($invited);
    }

    /**
     * RB-4 · **de un solo uso**. El segundo intento con el mismo enlace ya no
     * encuentra nada y cae en la pantalla de SP-3.
     */
    public function test_the_link_works_exactly_once(): void
    {
        [, $invitation] = $this->pendingInvitation();

        $this->post('/invitacion/'.$invitation->plainToken, [
            'password' => 'Contrasena-Larga-9',
            'password_confirmation' => 'Contrasena-Larga-9',
        ])->assertRedirect('/backoffice');

        // Se sale por la puerta de verdad: aceptar deja la sesión abierta, y
        // el enlace solo lo vuelve a abrir quien no la tiene.
        $this->post('/backoffice/logout');

        $this->get('/invitacion/'.$invitation->plainToken)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/InvitationExpired'));
    }

    /**
     * SP-3 · caducado **no es un error crudo**: es una pantalla que explica qué
     * pasó, y el token queda marcado como consumido. Quien llega ahí solo
     * tardó tres días en abrir el correo.
     */
    public function test_an_expired_link_shows_the_resend_screen_and_burns_the_token(): void
    {
        $invitation = $this->issue();

        $invited = $this->withinOrganization(
            $this->organization,
            fn (): User => User::factory()->withExpiredInvitation($invitation)->create(),
        );

        $this->get('/invitacion/'.$invitation->plainToken)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/auth/InvitationExpired')
                ->where('organizationName', $this->organization->name));

        $this->assertNull($invited->refresh()->invitation_token_hash, 'El token caducado no quedó consumido.');

        // Y su sitio sigue reservado: la persona no se borra, así que un
        // reenvío le emite otro enlace sin volver a crearla.
        $this->assertDatabaseHas('users', ['id' => $invited->getKey()]);
    }

    /**
     * Un enlace vivo **no se gasta por mirarlo**. Si `GET` consumiera, el
     * primer antivirus de correo que visita los enlaces para comprobarlos
     * quemaría la invitación antes de que la persona llegara a verla.
     */
    public function test_opening_a_live_link_does_not_burn_it(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->get('/invitacion/'.$invitation->plainToken)->assertOk();
        $this->get('/invitacion/'.$invitation->plainToken)->assertOk();

        $this->assertNotNull($invited->refresh()->invitation_token_hash);
    }

    /**
     * SP-6 · el reenvío invalida el anterior: **solo el último enlace
     * funciona**. Es lo que evita que «reenviar» multiplique las puertas
     * abiertas.
     */
    public function test_resending_invalidates_the_previous_link(): void
    {
        [$invited, $first] = $this->pendingInvitation();

        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        // El reenvío se hace por la acción y no por la ruta —para eso está
        // `test_resending_does_not_consume_another_slot`— porque este caso
        // necesita después una petición **sin sesión**, la de quien abre el
        // enlace viejo, y un `actingAs` previo la contaminaría.
        $this->withinOrganization(
            $this->organization,
            fn () => app(InviteTeamMemberAction::class)->resend($invited, $owner),
        );

        $this->get('/invitacion/'.$first->plainToken)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/InvitationExpired'));

        Notification::assertSentToTimes($invited, TeamInvitationNotification::class, 1);

        // Y el nuevo sí abre: el reenvío emitió otro, no rompió la invitación.
        $this->assertNotNull($invited->refresh()->invitation_token_hash);
        $this->assertNotSame($first->tokenHash, $invited->invitation_token_hash);
    }

    /**
     * El reenvío no suma a nadie: la persona ya ocupaba su sitio desde que se
     * la invitó (RB-2).
     */
    public function test_resending_does_not_consume_another_slot(): void
    {
        [$invited] = $this->pendingInvitation();

        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->actingAs($owner)->post('/backoffice/equipo/'.$invited->getKey().'/reenviar');

        $this->assertSame(2, $this->withinOrganization(
            $this->organization,
            fn (): int => (int) User::query()->whereNull('disabled_at')->count(),
        ));
    }

    /**
     * `REQ-ORG-002` · el enlace lleva su organización delante, así que un
     * token con el prefijo de otra no resuelve a nadie. Sin esto, el hash
     * tendría que ser único en toda la plataforma.
     */
    public function test_a_token_pointing_at_another_organization_opens_nothing(): void
    {
        [, $invitation] = $this->pendingInvitation();

        $forged = $this->neighbourOrganization()->getKey().'.'.Str::after($invitation->plainToken, '.');

        $this->get('/invitacion/'.$forged)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/InvitationExpired'));
    }

    public function test_a_malformed_token_shows_the_same_screen(): void
    {
        $this->get('/invitacion/esto-no-es-un-token')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/auth/InvitationExpired')
                ->where('organizationName', null));
    }

    /**
     * RB-5 · la contraseña sigue la política de `REQ-BOF-001` RB-6. Aflojarla
     * aquí sería aflojarla para todo el equipo menos para el dueño.
     */
    public function test_a_weak_password_is_rejected(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->postJson('/invitacion/'.$invitation->plainToken, [
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull($invited->refresh()->password);
    }

    /**
     * SP-5 · aceptada **después** de que la organización quedara suspendida:
     * la persona entra igual y se encuentra el aviso. No es un caso raro; es
     * lo que pasa cuando alguien invita y deja de pagar la misma semana.
     */
    public function test_accepting_after_a_suspension_still_creates_the_access(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->post('/invitacion/'.$invitation->plainToken, [
            'password' => 'Contrasena-Larga-9',
            'password_confirmation' => 'Contrasena-Larga-9',
        ])->assertRedirect('/backoffice');

        $this->assertNotNull($invited->refresh()->password);
        $this->assertAuthenticatedAs($invited);

        // Entra al panel, que es donde se ve el aviso para regularizar, y no
        // puede crear nada: es el modo restringido de `REQ-ORG-008` RB-3.
        $this->get('/backoffice')->assertOk();
        $this->get('/backoffice/equipo')->assertForbidden();
    }

    // -------------------------------------------------------------------

    /**
     * Una invitación pendiente con su enlace vivo.
     *
     * @return array{0: User, 1: TeamInvitation}
     */
    private function pendingInvitation(): array
    {
        $invitation = $this->issue();

        $invited = $this->withinOrganization(
            $this->organization,
            fn (): User => User::factory()->invited($invitation)->create(),
        );

        $this->withoutOrganization();

        return [$invited, $invitation];
    }

    private function issue(): TeamInvitation
    {
        return TeamInvitation::issueFor($this->organization);
    }
}
