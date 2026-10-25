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
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TeamInvitationTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->plan = Plan::factory()->create(['name' => 'Equipo', 'position' => 1, 'max_users' => 3]);

        $this->subscribe($neighbour);
        $this->withinOrganization($neighbour, fn () => User::factory()->count(3)->create());

        $this->subscribe($this->organization);
        $this->withoutOrganization();
    }

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

        $this->assertNull($invited->password);
        $this->assertNotNull($invited->invited_at);
        $this->assertNotNull($invited->invitation_token_hash);
        $this->assertSame(OrganizationRole::Member, $invited->role);

        Notification::assertSentTo($invited, TeamInvitationNotification::class);
    }

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

    public function test_a_member_cannot_invite(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)
            ->post('/backoffice/equipo', ['name' => 'Marta', 'email' => 'marta@ejemplo.test', 'role' => 'member'])
            ->assertForbidden();

        $this->actingAs($member)->get('/backoffice/equipo')->assertForbidden();
    }

    public function test_a_pending_invitation_fills_the_plan(): void
    {
        $owner = $this->owner();

        $this->withinOrganization($this->organization, fn () => User::factory()->invited()->count(2)->create());
        $this->recalculateUsage($this->organization);

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('limit.metric', 'users')
            ->assertJsonPath('limit.used', 3)
            ->assertJsonPath('limit.limit', 3)
            ->assertJsonPath('upgradeUrl', '/backoffice/suscripcion');
    }

    public function test_a_full_plan_answers_with_the_plan_that_unlocks_it(): void
    {
        Plan::factory()->create(['code' => 'grande', 'name' => 'Grande', 'position' => 2, 'max_users' => 20]);

        $owner = $this->owner();
        $this->withinOrganization($this->organization, fn () => User::factory()->count(2)->create());
        $this->recalculateUsage($this->organization);

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('limit.minimumPlan', 'grande')
            ->assertJsonPath('limit.minimumPlanName', 'Grande');
    }

    public function test_revoking_a_pending_invitation_frees_its_slot(): void
    {
        $owner = $this->owner();

        $pending = $this->withinOrganization($this->organization, function (): User {
            User::factory()->create();

            return User::factory()->invited()->create();
        });
        $this->recalculateUsage($this->organization);

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->delete('/backoffice/equipo/'.$pending->getKey())
            ->assertRedirect('/backoffice/equipo');

        $this->assertDatabaseMissing('users', ['id' => $pending->getKey()]);

        $this->assertSame(2, $this->consumption());

        $this->actingAs($owner)
            ->post('/backoffice/equipo', ['name' => 'Nueva', 'email' => 'nueva@ejemplo.test', 'role' => 'member'])
            ->assertRedirect('/backoffice/equipo');

        $this->assertSame(3, $this->consumption());
    }

    public function test_an_accepted_member_cannot_be_revoked(): void
    {
        $owner = $this->owner();
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($owner)
            ->deleteJson('/backoffice/equipo/'.$member->getKey())
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }

    public function test_an_email_of_another_organization_is_invited_but_not_one_of_this(): void
    {
        $owner = $this->owner();

        $neighbourMember = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(['email' => 'repetida@ejemplo.test']),
        );

        $this->actingAs($owner)
            ->post('/backoffice/equipo', ['name' => 'Repetida', 'email' => $neighbourMember->email, 'role' => 'member'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, User::withoutTenantScope()->where('email', $neighbourMember->email)->count());

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Otra vez', 'email' => $neighbourMember->email, 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'Ese correo ya es del equipo de tu negocio.']);
    }

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

    public function test_the_page_lists_the_team_with_its_plan_consumption(): void
    {
        $owner = $this->owner();

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->create(['name' => 'Activa']);
            User::factory()->disabled()->create(['name' => 'Inactiva']);
        });
        $this->recalculateUsage($this->organization);

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

    public function test_the_team_routes_do_not_survive_a_suspension(): void
    {
        $owner = $this->owner();

        $this->organization->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($owner)->get('/backoffice/equipo')->assertForbidden();

        $this->actingAs($owner)
            ->post('/backoffice/equipo', ['name' => 'Marta', 'email' => 'marta@ejemplo.test', 'role' => 'member'])
            ->assertForbidden();

        $this->actingAs($owner)->get('/backoffice')->assertOk();
    }

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
