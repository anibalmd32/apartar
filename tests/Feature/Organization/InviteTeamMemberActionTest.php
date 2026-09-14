<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\InviteTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\InvitationNotPendingException;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TeamInvitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La acción de invitar, por debajo de la ruta (`REQ-ORG-004`).
 *
 * Lo que se comprueba aquí es lo que el recorrido por HTTP no ve: que la
 * autorización vive **también** en la acción y no solo en el middleware `owner`
 * del grupo de rutas, y que el enlace que se emite es el que viaja en el
 * correo.
 */
final class InviteTeamMemberActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_users' => 5]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();
                UsageCounter::factory()->forMetric(UsageMetric::Users)->create();
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->actingAsOrganization($this->organization);
    }

    /**
     * RB-1 y SP-4 · la policy se comprueba **dentro** de la acción.
     *
     * El middleware `owner` protege el camino; esto protege la operación. Hacen
     * falta las dos: una acción invocada desde un job, un comando o otra
     * acción no pasa por ningún grupo de rutas.
     */
    public function test_only_the_owner_may_invite(): void
    {
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $member);
    }

    /**
     * RB-4 · el enlace que se emite es el que llega al correo, y el que queda
     * en la fila es **su hash**. Quien lea la tabla no puede entrar con lo que
     * ve.
     */
    public function test_the_stored_token_is_the_hash_of_the_emailed_link(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $owner);

        Notification::assertSentTo(
            $invited,
            TeamInvitationNotification::class,
            function (TeamInvitationNotification $notification) use ($invited): bool {
                $url = $notification->toMail($invited)->actionUrl;
                $token = urldecode(Str::afterLast($url, '/'));

                return TeamInvitation::hash($token) === $invited->invitation_token_hash;
            },
        );

        $this->assertNotSame($invited->invitation_token_hash, $invited->getKey());
    }

    /**
     * El enlace nace con su plazo puesto: una invitación pendiente sin
     * vencimiento sería una puerta abierta para siempre.
     */
    public function test_the_invitation_is_born_with_its_deadline(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $owner);

        $this->assertNotNull($invited->invitation_expires_at);
        $this->assertTrue($invited->invitation_expires_at->isFuture());
        $this->assertTrue($invited->invitation_expires_at->lessThanOrEqualTo(
            now()->addHours(TeamInvitation::TTL_HOURS),
        ));
    }

    /**
     * RB-6 · revocar y reenviar son del ciclo de vida de la **invitación**. A
     * quien ya entró no se le aplica ninguna de las dos: se le desactiva.
     */
    public function test_an_accepted_member_is_neither_resent_nor_revoked(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        try {
            $this->action()->resend($member, $owner);
            $this->fail('Reenviar a quien ya aceptó debió fallar.');
        } catch (InvitationNotPendingException) {
            // Esperado.
        }

        $this->expectException(InvitationNotPendingException::class);

        $this->action()->revoke($member, $owner);
    }

    /**
     * Se puede invitar como `owner`: `REQ-ORG-003` RB-3 admite varios, y un
     * negocio con un solo dueño es un negocio a una baja de quedarse fuera.
     */
    public function test_someone_may_be_invited_straight_as_an_owner(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Socia', 'socia@ejemplo.test', OrganizationRole::Owner, $owner);

        $this->assertSame(OrganizationRole::Owner, $invited->role);
        $this->assertNull($invited->password, 'Ni siquiera un owner invitado entra sin fijar su contraseña.');
    }

    /**
     * SP-2 · el correo duplicado lo para **el índice**, no solo el
     * `FormRequest`.
     *
     * La regla `unique` del request da el mensaje junto al campo, que es lo
     * único que le sirve a quien está escribiendo; el índice cierra la ventana
     * entre aquella consulta y el `INSERT`, que es lo único que sirve cuando
     * llegan dos a la vez. Este caso llama a la acción directamente,
     * saltándose el request, que es lo que hace esa ventana.
     */
    public function test_the_unique_index_rejects_a_duplicate_email_without_the_request(): void
    {
        $owner = User::factory()->owner()->create();

        $this->withinOrganization(
            $this->neighbourOrganization(),
            fn () => User::factory()->create(['email' => 'repetida@ejemplo.test']),
        );

        try {
            $this->action()->handle('Repetida', 'repetida@ejemplo.test', OrganizationRole::Member, $owner);
            $this->fail('El correo duplicado debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        // Y la transacción se deshizo entera: sin usuario a medias y sin
        // haberle quitado un sitio al plan.
        $this->assertSame(1, (int) User::query()->count());
    }

    /**
     * `REQ-ORG-002` RB-2 · la acción nunca escribe `organization_id`: lo pone
     * el trait desde el contexto. El invitado nace en la organización activa y
     * no en la de nadie más.
     */
    public function test_the_invited_person_lands_in_the_active_organization(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $owner);

        $this->assertSame($this->organization->getKey(), $invited->organization_id);
    }

    private function action(): InviteTeamMemberAction
    {
        return app(InviteTeamMemberAction::class);
    }
}
