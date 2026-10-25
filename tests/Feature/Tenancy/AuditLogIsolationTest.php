<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class AuditLogIsolationTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $mine;

    private Organization $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbour = $this->neighbourOrganization();
        $this->mine = Organization::factory()->create();

        $plan = Plan::factory()->create();

        foreach ([$this->neighbour, $this->mine] as $organization) {
            $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($plan)->create());
        }

        $this->withinOrganization($this->neighbour, fn () => $this->writeEntry('Ajuste de la vecina'));
        $this->withinOrganization($this->mine, fn () => $this->writeEntry('Ajuste mío'));

        $this->withoutOrganization();
    }

    public function test_the_scenario_has_two_organizations_with_entries(): void
    {
        $this->assertTrue($this->hasNeighbourOrganization());

        foreach ([$this->mine, $this->neighbour] as $organization) {
            $this->assertSame(
                1,
                $this->withinOrganization($organization, fn (): int => AuditEntry::query()->count()),
            );
        }
    }

    public function test_a_query_only_sees_the_entries_of_the_active_organization(): void
    {
        $this->actingAsOrganization($this->mine);

        $this->assertSame(1, AuditEntry::query()->count());
        $this->assertSame('Ajuste mío', AuditEntry::query()->sole()->description);
    }

    public function test_the_neighbour_cannot_see_our_entries(): void
    {
        $this->actingAsOrganization($this->neighbour);

        $this->assertSame('Ajuste de la vecina', AuditEntry::query()->sole()->description);
    }

    public function test_the_audit_page_never_shows_another_organizations_entries(): void
    {
        $owner = $this->withinOrganization($this->mine, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        $this->actingAs($owner)
            ->get('/backoffice/auditoria')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.description', 'Ajuste mío'));
    }

    public function test_an_entry_of_another_organization_is_not_found_by_id(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): int => (int) AuditEntry::query()->firstOrFail()->getKey(),
        );

        $this->actingAsOrganization($this->mine);

        $this->assertNull(AuditEntry::query()->find($foreign));
    }

    public function test_querying_the_audit_log_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        AuditEntry::query()->count();
    }

    private function writeEntry(string $description): void
    {
        AuditEntry::query()->create([
            'log_name' => AuditEntry::LOG_NAME,
            'event' => AuditEvent::SettingsUpdated->value,
            'description' => $description,
            'properties' => ['changed' => []],
        ]);
    }
}
