<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LimitEnforcerTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withinOrganization($neighbour, function (): void {
            Subscription::factory()->forPlan(Plan::factory()->hidden()->create())->create();
            User::factory()->count(3)->create();
        });
    }

    public function test_a_pending_invitation_consumes_a_slot(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            User::factory()->invited()->create();
            $this->recalculateUsage();

            $this->assertSame(2, $this->enforcer()->usageOf(UsageMetric::Users)->used);
            $this->assertSame(3, $this->enforcer()->remaining(UsageMetric::Users));
        });
    }

    public function test_a_disabled_member_frees_its_slot(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            $member = User::factory()->create();
            $this->recalculateUsage();

            $this->assertSame(2, $this->enforcer()->usageOf(UsageMetric::Users)->used);

            $member->forceFill(['disabled_at' => now()])->save();
            $this->recalculateUsage();

            $this->assertSame(1, $this->enforcer()->usageOf(UsageMetric::Users)->used);
        });
    }

    public function test_a_null_limit_never_blocks(): void
    {
        $plan = Plan::factory()->unlimited()->create();
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->count(40)->create();

            $this->assertNull($this->enforcer()->remaining(UsageMetric::Users));

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));

            $this->assertSame(0, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
        });
    }

    public function test_a_full_plan_names_the_one_that_unlocks_it(): void
    {
        $free = Plan::factory()->create(['code' => 'free-t013', 'name' => 'Free', 'position' => 1, 'max_users' => 1]);
        Plan::factory()->create(['code' => 'pro-t013', 'name' => 'Pro', 'position' => 2, 'max_users' => 5]);

        $this->subscribeTo(plan: $free);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            $this->recalculateUsage();

            try {
                DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));
                $this->fail('El plan lleno debió rechazar la creación.');
            } catch (PlanLimitReachedException $exception) {
                $this->assertSame(UsageMetric::Users, $exception->metric);
                $this->assertSame(1, $exception->used);
                $this->assertSame(1, $exception->limit);
                $this->assertSame('pro-t013', $exception->minimumPlan?->code);
            }
        });
    }

    public function test_with_only_the_current_plan_public_no_plans_are_offered(): void
    {
        $free = Plan::factory()->create(['code' => 'free-solo', 'name' => 'Free', 'position' => 1]);
        Plan::factory()->hidden()->create(['code' => 'oculto', 'name' => 'Oculto', 'position' => 2]);

        $this->subscribeTo(plan: $free);

        $this->assertFalse($this->withinOrganization($this->organization, fn (): bool => $this->enforcer()->state()->plansOffered));

        Plan::factory()->create(['code' => 'visible', 'name' => 'Visible', 'position' => 3]);

        $this->assertTrue($this->withinOrganization($this->organization, fn (): bool => $this->enforcer()->state()->plansOffered));
    }

    public function test_a_hidden_plan_is_never_offered_as_the_way_out(): void
    {
        $free = Plan::factory()->create(['code' => 'free-h', 'name' => 'Free', 'position' => 1, 'max_users' => 1]);
        Plan::factory()->hidden()->create(['code' => 'negociado', 'name' => 'Negociado', 'position' => 2, 'max_users' => 99]);

        $this->subscribeTo(plan: $free);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            $this->recalculateUsage();

            try {
                DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));
                $this->fail('El plan lleno debió rechazar la creación.');
            } catch (PlanLimitReachedException $exception) {
                $this->assertNull($exception->minimumPlan);
            }
        });
    }

    public function test_a_suspended_subscription_blocks_everything(): void
    {
        $plan = Plan::factory()->create(['max_users' => 50]);

        $this->withinOrganization(
            $this->organization,
            fn () => Subscription::factory()->suspended()->forPlan($plan)->create(),
        );

        $this->withinOrganization($this->organization, function (): void {
            $this->expectException(CreationSuspendedException::class);

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));
        });
    }

    public function test_a_past_due_subscription_still_creates(): void
    {
        $plan = Plan::factory()->create(['max_users' => 50]);

        $this->withinOrganization(
            $this->organization,
            fn () => Subscription::factory()->pastDue()->forPlan($plan)->create(),
        );

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));

            $this->assertTrue(UsageCounter::query()->where('metric', UsageMetric::Users)->exists());
        });
    }

    public function test_the_locked_check_trusts_the_counter_and_the_recalculation_corrects_it(): void
    {
        $this->subscribeTo(maxUsers: 3);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->count(3)->create();

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));

            $this->assertSame(0, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));

            $this->recalculateUsage();

            $this->expectException(PlanLimitReachedException::class);

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));
        });
    }

    public function test_the_state_covers_the_six_metrics(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            $this->recalculateUsage();

            $state = $this->enforcer()->state();

            $this->assertCount(6, $state->usage);
            $this->assertSame(1, $state->usageOf(UsageMetric::Users)->used);
            $this->assertSame(0, $state->usageOf(UsageMetric::Products)->used);
            $this->assertSame('active', $state->toArray()['status']);
        });
    }

    public function test_a_feature_the_plan_lacks_is_unavailable_and_says_which_plan_has_it(): void
    {
        $free = Plan::factory()->create(['code' => 'free-f', 'name' => 'Free', 'position' => 1]);
        Plan::factory()->withEveryFeature()->create(['code' => 'pro-f', 'name' => 'Pro', 'position' => 2]);

        $this->subscribeTo(plan: $free);

        $this->withinOrganization($this->organization, function (): void {
            $this->assertFalse($this->enforcer()->hasFeature(PlanFeature::Branding));

            try {
                $this->enforcer()->assertHasFeature(PlanFeature::Branding);
                $this->fail('La función ausente debió rechazarse.');
            } catch (PlanFeatureUnavailableException $exception) {
                $this->assertSame('pro-f', $exception->minimumPlan?->code);
                $this->assertSame(403, PlanFeatureUnavailableException::STATUS);
            }
        });
    }

    public function test_a_metric_is_enforced_from_its_counter(): void
    {
        $plan = Plan::factory()->create(['max_products' => 2]);
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            UsageCounter::query()->where('metric', UsageMetric::Products)->update(['value' => 2]);

            $this->assertSame(2, $this->enforcer()->usageOf(UsageMetric::Products)->used);
            $this->assertSame(0, $this->enforcer()->remaining(UsageMetric::Products));

            $this->expectException(PlanLimitReachedException::class);

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Products));
        });
    }

    public function test_a_feature_the_plan_includes_passes_silently(): void
    {
        $plan = Plan::factory()->withEveryFeature()->create();
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            $this->enforcer()->assertHasFeature(PlanFeature::Branding);

            $this->assertTrue($this->enforcer()->hasFeature(PlanFeature::Branding));
        });
    }

    public function test_the_plan_is_the_active_organizations(): void
    {
        $plan = Plan::factory()->withEveryFeature()->create(['code' => 'pro-p']);
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            $this->assertSame('pro-p', $this->enforcer()->plan()->code);
            $this->assertFalse($this->enforcer()->plan()->showsPlatformBadge());
        });
    }

    public function test_the_neighbours_consumption_is_not_ours(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, fn () => User::factory()->owner()->create());
        $this->recalculateUsage($this->organization);
        $this->recalculateUsage($this->neighbourOrganization());

        $ours = $this->withinOrganization(
            $this->organization,
            fn (): int => $this->enforcer()->usageOf(UsageMetric::Users)->used,
        );

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): int => $this->enforcer()->usageOf(UsageMetric::Users)->used,
        );

        $this->assertSame(1, $ours);
        $this->assertSame(3, $theirs);
    }

    private function enforcer(): LimitEnforcer
    {
        return app(LimitEnforcer::class);
    }

    private function subscribeTo(?Plan $plan = null, ?int $maxUsers = null): void
    {
        $plan ??= Plan::factory()->create($maxUsers === null ? [] : ['max_users' => $maxUsers]);

        $this->withinOrganization($this->organization, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();

            foreach (UsageMetric::cases() as $metric) {
                UsageCounter::factory()->forMetric($metric)->create();
            }
        });
    }
}
