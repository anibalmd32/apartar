<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Auditing\AuditTrail;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cómo se escribe una entrada de auditoría (`REQ-BOF-005` RB-2, RB-6, RB-7,
 * SP-1, SP-5).
 *
 * Lo que se prueba aquí es el **mecanismo**: los tres actores de RB-7, el
 * sujeto que sigue legible cuando su fila desaparece y el `organization_id` que
 * nadie asigna a mano. Que cada `Action` lo use está en `AuditedActionsTest`.
 */
final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    /**
     * RB-7 · **un `User` del equipo.** El caso normal.
     */
    public function test_a_team_member_is_recorded_as_the_causer(): void
    {
        $actor = User::factory()->owner()->create();

        $this->trail()->record(AuditEvent::SettingsUpdated, 'Ajustes cambiados', null, $actor, []);

        $entry = AuditEntry::query()->sole();

        $this->assertSame($actor->getMorphClass(), $entry->causer_type);
        $this->assertSame((int) $actor->getKey(), (int) $entry->causer_id);
    }

    /**
     * **SP-1 · un job sin persona deja `causer` nulo**, y la descripción dice
     * qué proceso fue. Sin esa segunda mitad, la entrada diría que algo pasó y
     * nada más.
     */
    public function test_a_process_without_an_actor_leaves_a_null_causer(): void
    {
        $this->trail()->record(
            AuditEvent::SubscriptionSuspended,
            'Suscripción suspendida · subscriptions:dunning',
            null,
            null,
            [],
        );

        $entry = AuditEntry::query()->sole();

        $this->assertNull($entry->causer_type);
        $this->assertNull($entry->causer_id);
        $this->assertStringContainsString('subscriptions:dunning', $entry->description);
    }

    /**
     * **RB-6 y RB-7 · el actor puede no ser del tenant, y el esquema tiene que
     * admitirlo desde hoy.**
     *
     * `PlatformUser` llega con `T-086`; lo que se comprueba aquí es que el
     * `causer` polimórfico acepta un tipo que todavía no tiene modelo, para que
     * H8 **no tenga que migrar la tabla**. Si la columna fuera una clave
     * foránea a `users`, esta inserción fallaría — y el modelo de soporte
     * defendible de RB-6 nacería roto.
     */
    public function test_the_causer_admits_an_actor_that_is_not_a_team_member(): void
    {
        AuditEntry::query()->create([
            'log_name' => AuditEntry::LOG_NAME,
            'event' => AuditEvent::SupportAccessGranted->value,
            'description' => 'Acceso de soporte de Apartar concedido',
            'causer_type' => 'App\Models\PlatformUser',
            'causer_id' => 7,
            'properties' => [],
        ]);

        $entry = AuditEntry::query()->sole();

        $this->assertSame('App\Models\PlatformUser', $entry->causer_type);
        $this->assertSame(7, (int) $entry->causer_id);
        $this->assertSame((int) $this->organization->getKey(), $entry->organization_id);
    }

    /**
     * El sujeto viaja como morph y **su nombre queda en la descripción**
     * (SP-5): una ficha archivada o un miembro dado de baja siguen dejando la
     * entrada legible, porque el nombre se guardó cuando el hecho ocurrió.
     */
    public function test_the_subject_stays_readable_after_its_row_is_gone(): void
    {
        $actor = User::factory()->owner()->create();
        $member = User::factory()->create(['name' => 'Ana Pérez']);

        $this->trail()->record(
            AuditEvent::TeamInvitationRevoked,
            'Invitación al equipo revocada: Ana Pérez',
            $member,
            $actor,
            [],
        );

        $member->delete();

        $entry = AuditEntry::query()->where('event', AuditEvent::TeamInvitationRevoked->value)->sole();

        $this->assertStringContainsString('Ana Pérez', $entry->description);
        $this->assertSame((int) $member->getKey(), (int) $entry->subject_id);
    }

    /**
     * `organization_id` lo rellena el trait, no el escritor: una `Action` no
     * puede elegir en qué organización deja su rastro.
     */
    public function test_the_organization_is_filled_by_the_tenant_and_not_by_the_writer(): void
    {
        $actor = User::factory()->owner()->create();

        $this->trail()->record(AuditEvent::IdentityUpdated, 'Identidad cambiada', null, $actor, []);

        $this->assertSame(
            (int) $this->organization->getKey(),
            AuditEntry::query()->sole()->organization_id,
        );
    }

    private function trail(): AuditTrail
    {
        return app(AuditTrail::class);
    }
}
