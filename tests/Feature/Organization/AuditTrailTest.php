<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Auditing\AuditTrail;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class AuditTrailTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    public function test_a_team_member_is_recorded_as_the_causer(): void
    {
        $actor = User::factory()->owner()->create();

        $this->trail()->record(AuditEvent::SettingsUpdated, 'Ajustes cambiados', null, $actor, []);

        $entry = AuditEntry::query()->sole();

        $this->assertSame($actor->getMorphClass(), $entry->causer_type);
        $this->assertSame((int) $actor->getKey(), (int) $entry->causer_id);
    }

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
