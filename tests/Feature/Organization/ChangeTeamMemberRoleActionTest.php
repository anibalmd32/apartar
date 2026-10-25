<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\ChangeTeamMemberRoleAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ChangeTeamMemberRoleActionTest extends TestCase
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
        $this->actingAsOrganization($this->organization);
    }

    public function test_promoting_needs_no_guard(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $this->action()->handle($member, OrganizationRole::Owner, $owner);

        $this->assertSame(OrganizationRole::Owner, $member->refresh()->role);
        $this->assertSame(2, (int) User::query()->where('role', 'owner')->whereNull('disabled_at')->count());
    }

    public function test_demoting_the_last_active_owner_is_refused(): void
    {
        $owner = User::factory()->owner()->create();

        try {
            $this->action()->handle($owner, OrganizationRole::Member, $owner);
            $this->fail('Degradar al último owner debió fallar.');
        } catch (LastOwnerException $exception) {
            $this->assertStringContainsString('degradar', $exception->getMessage());
            $this->assertStringContainsString('último owner', $exception->getMessage());
        }

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    public function test_the_same_demotion_succeeds_while_another_owner_remains(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->action()->handle($owner, OrganizationRole::Member, $owner);

        $this->assertSame(OrganizationRole::Member, $owner->refresh()->role);
    }

    public function test_a_disabled_owner_does_not_hold_the_seat(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->disabled()->create();

        $this->expectException(LastOwnerException::class);

        $this->action()->handle($owner, OrganizationRole::Member, $owner);
    }

    public function test_setting_the_same_role_writes_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $before = $member->updated_at;

        $this->action()->handle($member, OrganizationRole::Member, $owner);

        $this->assertEquals($before, $member->refresh()->updated_at);
    }

    public function test_the_last_owner_may_be_set_to_owner_again(): void
    {
        $owner = User::factory()->owner()->create();

        $this->action()->handle($owner, OrganizationRole::Owner, $owner);

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    public function test_a_member_cannot_change_anyones_role(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($subject, OrganizationRole::Owner, $actor);
    }

    public function test_a_member_of_another_organization_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(),
        );

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($theirs, OrganizationRole::Owner, $owner);
    }

    private function action(): ChangeTeamMemberRoleAction
    {
        return app(ChangeTeamMemberRoleAction::class);
    }
}
