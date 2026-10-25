<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\DisableTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CannotDisableSelfException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DisableTeamMemberActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_the_row_survives_with_its_name(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create(['name' => 'Zoe']);

        $this->action()->handle($member, $owner);

        $this->assertNotNull($member->refresh()->disabled_at);
        $this->assertSame('Zoe', $member->name);
        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }

    public function test_nobody_disables_themselves(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->expectException(CannotDisableSelfException::class);

        $this->action()->handle($owner, $owner);
    }

    public function test_a_member_cannot_disable_anyone(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($subject, $actor);
    }

    public function test_disabling_twice_keeps_the_original_date(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        $original = $member->disabled_at;

        $this->action()->handle($member, $owner);

        $this->assertEquals($original, $member->refresh()->disabled_at);
    }

    public function test_it_frees_the_slot_in_the_same_transaction(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->count(2)->create();
        $member = User::factory()->create();

        $this->action()->handle($member, $owner);

        $this->assertSame(3, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
    }

    public function test_a_member_of_another_organization_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(),
        );

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($theirs, $owner);
    }

    private function action(): DisableTeamMemberAction
    {
        $this->recalculateUsage();

        return app(DisableTeamMemberAction::class);
    }
}
