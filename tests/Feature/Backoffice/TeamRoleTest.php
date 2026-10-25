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
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TeamRoleTest extends TestCase
{
    use ReusesMigratedDatabase;

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

    public function test_the_owner_promotes_a_member(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'owner'])
            ->assertRedirect('/backoffice/equipo');

        $this->assertSame(OrganizationRole::Owner, $member->refresh()->role);
    }

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

    public function test_the_last_active_owner_cannot_demote_themselves(): void
    {
        [$owner] = $this->team();

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$owner->getKey().'/rol', ['role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', fn (string $message): bool => str_contains($message, 'último owner'));

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    public function test_a_disabled_owner_does_not_hold_the_seat(): void
    {
        [$owner] = $this->team();

        $this->withinOrganization($this->organization, fn () => User::factory()->owner()->disabled()->create());

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$owner->getKey().'/rol', ['role' => 'member'])
            ->assertStatus(422);

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    public function test_a_member_calling_the_route_is_forbidden(): void
    {
        [, $member] = $this->team();

        $this->actingAs($member)
            ->post('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'owner'])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Member, $member->refresh()->role);
    }

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

    public function test_an_unknown_role_is_rejected(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'superadmin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(OrganizationRole::Member, $member->refresh()->role);
    }

    public function test_the_route_does_not_survive_a_suspension(): void
    {
        [$owner, $member] = $this->team();

        $this->organization->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/rol', ['role' => 'owner'])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Member, $member->refresh()->role);
    }

    /**
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

    private function signIn(User $user): void
    {
        $this->post('/backoffice/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }
}
