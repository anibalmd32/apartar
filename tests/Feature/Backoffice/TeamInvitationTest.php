<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Invitar al equipo por HTTP (`REQ-ORG-004`).
 *
 * El recorrido del enlace —aceptarlo, reenviarlo, verlo caducado— está en
 * `AcceptInvitationTest`. Aquí está quién puede invitar, qué cuenta para el
 * plan y qué pasa cuando no cabe nadie más.
 */
final class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // REQ-QAX-003 RB-10: la vecina, con su equipo lleno. Si el consumo o
        // el listado se colaran, sus miembros aparecerían en los nuestros.
        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->plan = Plan::factory()->create(['name' => 'Equipo', 'position' => 1, 'max_users' => 3]);

        $this->subscribe($neighbour);
        $this->withinOrganization($neighbour, fn () => User::factory()->count(3)->create());

        $this->subscribe($this->organization);
        $this->withoutOrganization();
    }

    /**
     * RB-1 y el happy path: el `owner` invita y el invitado recibe su enlace.
     */
    public function test_the_owner_invites_and_the_link_is_sent(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/backoffice/equipo', [
                'name' => 'Marta',
                'email' => 'Marta@Ejemplo.test',
                'role' => 'member',
            ])
            ->assertRedirect('/backoffice/equipo');

        $invited = $this->withinOrganization(
            $this->organization,
            fn (): ?User => User::query()->where('email', 'marta@ejemplo.test')->first(),
        );

        $this->assertInstanceOf(User::class, $invited);

        // RB-3 · existe, pero sin contraseña utilizable y con su marca de
        // invitación. No puede entrar hasta establecerla.
        $this->assertNull($invited->password);
        $this->assertNotNull($invited->invited_at);
        $this->assertNotNull($invited->invitation_token_hash);
        $this->assertSame(OrganizationRole::Member, $invited->role);

        Notification::assertSentTo($invited, TeamInvitationNotification::class);
    }

    /**
     * El correo se normaliza antes de guardarse: `Ana@X.com` y `ana@x.com` son
     * la misma persona, y el índice único las dejaría convivir con dos cuentas
     * y dos sesiones.
     */
    public function test_the_email_is_stored_lowercased(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/backoffice/equipo', [
            'name' => 'Marta',
            'email' => '  MARTA@Ejemplo.TEST ',
            'role' => 'member',
        ]);

        $emails = $this->withinOrganization(
            $this->organization,
            fn (): array => User::query()->pluck('email')->all(),
        );

        $this->assertContains('marta@ejemplo.test', $emails);
    }

    /**
     * SP-4 · un `member` no invita. Es `403` y no `404`: el recurso es de su
     * propia organización, así que no hay nada que ocultar
     * (`REQ-ORG-002` RB-6 habla de recursos ajenos).
     */
    public function test_a_member_cannot_invite(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)
            ->post('/backoffice/equipo', ['name' => 'Marta', 'email' => 'marta@ejemplo.test', 'role' => 'member'])
            ->assertForbidden();

        $this->actingAs($member)->get('/backoffice/equipo')->assertForbidden();
    }

    /**
     * RB-2 · **la invitación pendiente cuenta para el límite**, que es la regla
     * que trae `LimitEnforcer` a esta tarea. Con el plan lleno de invitaciones
     * sin aceptar, no entra nadie más.
     */
    public function test_a_pending_invitation_fills_the_plan(): void
    {
        $owner = $this->owner();

        $this->withinOrganization($this->organization, fn () => User::factory()->invited()->count(2)->create());

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('limit.metric', 'users')
            ->assertJsonPath('limit.used', 3)
            ->assertJsonPath('limit.limit', 3)
            ->assertJsonPath('upgradeUrl', '/backoffice/suscripcion');
    }

    /**
     * SP-1 · y el `422` **nombra el plan que lo desbloquea** con su enlace a
     * Suscripción. Sin eso, la pantalla solo puede decir «no puedes».
     */
    public function test_a_full_plan_answers_with_the_plan_that_unlocks_it(): void
    {
        Plan::factory()->create(['code' => 'grande', 'name' => 'Grande', 'position' => 2, 'max_users' => 20]);

        $owner = $this->owner();
        $this->withinOrganization($this->organization, fn () => User::factory()->count(2)->create());

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('limit.minimumPlan', 'grande')
            ->assertJsonPath('limit.minimumPlanName', 'Grande');
    }

    /**
     * RB-6 · revocar **libera** la unidad, y se nota en que vuelve a caber
     * alguien donde antes no cabía.
     */
    public function test_revoking_a_pending_invitation_frees_its_slot(): void
    {
        $owner = $this->owner();

        $pending = $this->withinOrganization($this->organization, function (): User {
            User::factory()->create();

            return User::factory()->invited()->create();
        });

        // Tres de tres: el plan está lleno y con él no cabe nadie más.
        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->delete('/backoffice/equipo/'.$pending->getKey())
            ->assertRedirect('/backoffice/equipo');

        $this->assertDatabaseMissing('users', ['id' => $pending->getKey()]);

        // RB-6 · el hueco se liberó de verdad: entra alguien donde antes no
        // cabía, y el contador queda cuadrado en la misma transacción.
        $this->assertSame(2, $this->consumption());

        $this->actingAs($owner)
            ->post('/backoffice/equipo', ['name' => 'Nueva', 'email' => 'nueva@ejemplo.test', 'role' => 'member'])
            ->assertRedirect('/backoffice/equipo');

        $this->assertSame(3, $this->consumption());
    }

    /**
     * RB-1 de `REQ-ORG-005` visto desde el otro lado: a quien ya entró **no se
     * le borra**, se le desactiva. Revocar no es una puerta trasera al borrado.
     */
    public function test_an_accepted_member_cannot_be_revoked(): void
    {
        $owner = $this->owner();
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($owner)
            ->deleteJson('/backoffice/equipo/'.$member->getKey())
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }

    /**
     * SP-2 · `users.email` es único en **toda la plataforma**
     * (`REQ-ORG-001` RB-3), así que un correo tomado en otro negocio rebota.
     */
    public function test_an_email_used_in_any_organization_is_rejected(): void
    {
        $owner = $this->owner();

        $neighbourMember = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(['email' => 'repetida@ejemplo.test']),
        );

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', [
                'name' => 'Repetida',
                'email' => $neighbourMember->email,
                'role' => 'member',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /**
     * `REQ-ORG-002` RB-6 · operar sobre alguien de otra organización da `404`,
     * no `403`: no se confirma que exista.
     */
    public function test_a_member_of_another_organization_is_not_found(): void
    {
        $owner = $this->owner();

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(),
        );

        $this->actingAs($owner)->post('/backoffice/equipo/'.$theirs->getKey().'/desactivar')->assertNotFound();
        $this->actingAs($owner)->delete('/backoffice/equipo/'.$theirs->getKey())->assertNotFound();
    }

    /**
     * La pantalla trae el equipo **y el consumo**, para poder avisar antes de
     * que nadie choque con el techo (`REQ-SUB-003` RB-6). Y trae a los
     * desactivados: esconderlos dejaría al dueño sin poder reactivar a nadie.
     */
    public function test_the_page_lists_the_team_with_its_plan_consumption(): void
    {
        $owner = $this->owner();

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->create(['name' => 'Activa']);
            User::factory()->disabled()->create(['name' => 'Inactiva']);
        });

        $this->actingAs($owner)
            ->get('/backoffice/equipo')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/team/Index')
                ->has('members', 3)
                ->where('usage.used', 2)
                ->where('usage.limit', 3)
                ->where('usage.isAtLimit', false));
    }

    /**
     * RB-9 de `REQ-ORG-008` aplicada al equipo: **ninguna de estas rutas
     * sobrevive a la suspensión**. Ampliar el equipo mientras se le debe
     * dinero a la plataforma es lo contrario de «cobrar y aprobar
     * comprobantes», que es lo único que se conserva.
     */
    public function test_the_team_routes_do_not_survive_a_suspension(): void
    {
        $owner = $this->owner();

        $this->organization->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($owner)->get('/backoffice/equipo')->assertForbidden();

        $this->actingAs($owner)
            ->post('/backoffice/equipo', ['name' => 'Marta', 'email' => 'marta@ejemplo.test', 'role' => 'member'])
            ->assertForbidden();

        // Y el panel sí, que es donde se ve el aviso para regularizar.
        $this->actingAs($owner)->get('/backoffice')->assertOk();
    }

    // -------------------------------------------------------------------

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    private function consumption(): int
    {
        return $this->withinOrganization(
            $this->organization,
            fn (): int => (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'),
        );
    }

    /**
     * Suscribe a la organización con sus seis contadores a cero, como los deja
     * `StartFreeSubscriptionAction`.
     */
    private function subscribe(Organization $organization): void
    {
        $this->withinOrganization($organization, function (): void {
            Subscription::factory()->forPlan($this->plan)->create();

            foreach (UsageMetric::cases() as $metric) {
                UsageCounter::factory()->forMetric($metric)->create();
            }
        });
    }
}
