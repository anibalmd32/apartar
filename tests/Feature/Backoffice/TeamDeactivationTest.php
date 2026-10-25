<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Actions\Organization\DisableTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\AdminDisabledException;
use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TeamDeactivationTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->plan = Plan::factory()->create(['name' => 'Equipo', 'position' => 1, 'max_users' => 3]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function (): void {
                Subscription::factory()->forPlan($this->plan)->create();

                foreach (UsageMetric::cases() as $metric) {
                    UsageCounter::factory()->forMetric($metric)->create();
                }
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_disabling_is_logical_and_frees_a_slot(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/desactivar')
            ->assertRedirect('/backoffice/equipo');

        $member->refresh();

        $this->assertNotNull($member->disabled_at);
        $this->assertDatabaseHas('users', ['id' => $member->getKey(), 'name' => $member->name]);
        $this->assertSame(1, $this->consumption());
    }

    public function test_a_disabled_members_session_drops_on_the_next_request(): void
    {
        [$owner, $member] = $this->team();

        $this->post('/backoffice/login', ['email' => $member->email, 'password' => 'password']);

        $this->get('/backoffice')->assertOk();

        $this->actingAsOrganization($this->organization);
        app(DisableTeamMemberAction::class)->handle($member, $owner);
        $this->nextRequest();

        $this->get('/backoffice')
            ->assertRedirect('/backoffice/login')
            ->assertSessionHasErrors(['email' => AdminDisabledException::MESSAGE]);

        $this->assertGuest();
    }

    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }

    public function test_a_disabled_member_keeps_its_name_marked_as_inactive(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)->post('/backoffice/equipo/'.$member->getKey().'/desactivar');

        $this->actingAs($owner)
            ->get('/backoffice/equipo')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('members', 2)
                ->where('members.1.name', $member->name)
                ->where('members.1.isActive', false)
                ->where('members.0.isActive', true));
    }

    public function test_nobody_disables_themselves(): void
    {
        [$owner] = $this->team();

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$owner->getKey().'/desactivar')
            ->assertStatus(422);

        $this->assertNull($owner->refresh()->disabled_at);
    }

    public function test_the_last_active_owner_is_protected_by_the_guard(): void
    {
        [$first] = $this->team();

        $second = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->actingAs($first)
            ->post('/backoffice/equipo/'.$second->getKey().'/desactivar')
            ->assertRedirect('/backoffice/equipo');

        $this->assertSame(1, $this->activeOwners());

        $this->actingAsOrganization($this->organization);

        try {
            app(DisableTeamMemberAction::class)->handle($first, $second->refresh());
            $this->fail('Desactivar al último owner debió fallar.');
        } catch (LastOwnerException $exception) {
            $this->assertStringContainsString('último owner', $exception->getMessage());
        }

        $this->assertSame(1, $this->activeOwners());
        $this->assertNull($first->refresh()->disabled_at);
    }

    public function test_the_last_owner_cannot_walk_out_through_the_web(): void
    {
        [$first] = $this->team();

        $this->actingAs($first)
            ->postJson('/backoffice/equipo/'.$first->getKey().'/desactivar')
            ->assertStatus(422);

        $this->assertSame(1, $this->activeOwners());
    }

    public function test_reenabling_consumes_a_slot_and_forces_a_password_change(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)->post('/backoffice/equipo/'.$member->getKey().'/desactivar');

        $this->actingAs($owner)
            ->post('/backoffice/equipo/'.$member->getKey().'/activar')
            ->assertRedirect('/backoffice/equipo');

        $member->refresh();

        $this->assertNull($member->disabled_at);
        $this->assertTrue($member->must_change_password);
        $this->assertSame(2, $this->consumption());
    }

    public function test_reenabling_into_a_full_plan_is_rejected(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAs($owner)->post('/backoffice/equipo/'.$member->getKey().'/desactivar');

        $this->withinOrganization($this->organization, fn () => User::factory()->count(2)->create());
        $this->recalculateUsage($this->organization);

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo/'.$member->getKey().'/activar')
            ->assertStatus(422)
            ->assertJsonPath('limit.metric', 'users');

        $this->assertNotNull($member->refresh()->disabled_at);
    }

    public function test_downgrading_keeps_every_member_and_only_blocks_new_ones(): void
    {
        [$owner] = $this->team();

        $this->withinOrganization($this->organization, fn () => User::factory()->create());
        $this->recalculateUsage($this->organization);

        $small = Plan::factory()->create(['name' => 'Pequeño', 'position' => 0, 'max_users' => 1]);

        $this->withinOrganization(
            $this->organization,
            fn () => Subscription::query()->firstOrFail()->forceFill(['plan_id' => $small->getKey()])->save(),
        );

        $this->assertSame(3, $this->withinOrganization(
            $this->organization,
            fn (): int => (int) User::query()->whereNull('disabled_at')->count(),
        ));

        $this->actingAs($owner)->get('/backoffice/equipo')->assertOk();

        $this->actingAs($owner)
            ->postJson('/backoffice/equipo', ['name' => 'Cuarta', 'email' => 'cuarta@ejemplo.test', 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonPath('limit.used', 3)
            ->assertJsonPath('limit.limit', 1);
    }

    public function test_a_disabled_member_cannot_log_in(): void
    {
        [$owner, $member] = $this->team();

        $this->actingAsOrganization($this->organization);
        app(DisableTeamMemberAction::class)->handle($member, $owner);
        $this->withoutOrganization();

        $this->post('/backoffice/login', ['email' => $member->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function team(): array
    {
        /** @var array{0: User, 1: User} $team */
        $team = $this->withinOrganization($this->organization, fn (): array => [
            User::factory()->owner()->create(['name' => 'Ana Dueña']),
            User::factory()->create(['name' => 'Zoe Empleada']),
        ]);

        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();

        return $team;
    }

    private function consumption(): int
    {
        return $this->withinOrganization(
            $this->organization,
            fn (): int => (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'),
        );
    }

    private function activeOwners(): int
    {
        return $this->withinOrganization($this->organization, fn (): int => (int) User::query()
            ->where('role', 'owner')
            ->whereNull('disabled_at')
            ->count());
    }
}
