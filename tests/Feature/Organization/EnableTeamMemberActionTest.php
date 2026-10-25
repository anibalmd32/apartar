<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\EnableTeamMemberAction;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class EnableTeamMemberActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['name' => 'Tres', 'position' => 1, 'max_users' => 3]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();
                UsageCounter::factory()->forMetric(UsageMetric::Users)->create();
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->actingAsOrganization($this->organization);
    }

    public function test_it_restores_access_and_forces_a_password_change(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        $this->action()->handle($member, $owner);

        $member->refresh();

        $this->assertNull($member->disabled_at);
        $this->assertTrue($member->must_change_password);
    }

    public function test_it_consumes_a_slot_and_is_rejected_when_the_plan_is_full(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        User::factory()->count(2)->create();

        try {
            $this->action()->handle($member, $owner);
            $this->fail('Reactivar con el plan lleno debió fallar.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Users, $exception->metric);
        }

        $this->assertNotNull($member->refresh()->disabled_at, 'La reactivación rechazada dejó la baja a medias.');
    }

    public function test_the_counter_moves_with_the_reactivation(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        $this->action()->handle($member, $owner);

        $this->assertSame(2, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
    }

    public function test_reenabling_an_active_member_changes_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $this->action()->handle($member, $owner);

        $this->assertFalse($member->refresh()->must_change_password);
    }

    public function test_a_member_cannot_reenable_anyone(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->disabled()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($subject, $actor);
    }

    public function test_reenabling_an_owner_needs_no_guard(): void
    {
        $owner = User::factory()->owner()->create();
        $second = User::factory()->owner()->disabled()->create();

        $this->action()->handle($second, $owner);

        $this->assertSame(2, (int) User::query()->where('role', 'owner')->whereNull('disabled_at')->count());
    }

    private function action(): EnableTeamMemberAction
    {
        $this->recalculateUsage();

        return app(EnableTeamMemberAction::class);
    }
}
