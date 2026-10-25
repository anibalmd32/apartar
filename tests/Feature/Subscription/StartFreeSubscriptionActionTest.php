<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Domain\Subscription\MissingFreePlanException;
use App\Domain\Subscription\OrganizationAlreadySubscribedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StartFreeSubscriptionActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_the_free_subscription_is_born_active_and_open_ended(): void
    {
        $organization = $this->organizationWithNeighbour();

        $subscription = $this->action()->handle($organization);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->current_period_end);
        $this->assertTrue($subscription->isOpenEnded());
        $this->assertNull($subscription->cancelled_at);
        $this->assertNull($subscription->grace_ends_at);

        $this->assertTrue($subscription->started_at->isToday());
        $this->assertTrue($subscription->started_at->equalTo($subscription->current_period_start));
    }

    public function test_it_subscribes_to_the_free_plan(): void
    {
        $organization = $this->organizationWithNeighbour();

        $subscription = $this->action()->handle($organization);

        $this->assertSame(
            Plan::query()->where('code', StartFreeSubscriptionAction::FREE_PLAN_CODE)->sole()->getKey(),
            $subscription->plan_id,
        );
        $this->assertSame('Free', $subscription->plan->name);
    }

    public function test_it_subscribes_the_organization_it_was_given(): void
    {
        $organization = $this->organizationWithNeighbour();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($neighbour);

        $subscription = $this->action()->handle($organization);

        $this->assertSame((int) $organization->getKey(), $subscription->organization_id);
    }

    public function test_it_leaves_the_tenant_context_as_it_found_it(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->withoutOrganization();

        $this->action()->handle($organization);

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    public function test_it_initialises_every_counter_at_zero(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->action()->handle($organization);

        $counters = $this->withinOrganization(
            $organization,
            fn (): array => UsageCounter::query()->get()->all(),
        );

        $this->assertCount(count(UsageMetric::cases()), $counters);

        foreach ($counters as $counter) {
            $this->assertSame(0, $counter->value);
            $this->assertTrue($counter->computed_at->isToday());
        }

        $metrics = array_map(fn (UsageCounter $counter): string => $counter->metric->value, $counters);

        sort($metrics);
        $expected = array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases());
        sort($expected);

        $this->assertSame($expected, $metrics);
    }

    public function test_without_a_free_plan_it_fails_instead_of_subscribing_to_nothing(): void
    {
        $organization = $this->organizationWithNeighbour();

        Plan::query()->where('code', StartFreeSubscriptionAction::FREE_PLAN_CODE)->delete();

        try {
            $this->action()->handle($organization);

            $this->fail('Se esperaba MissingFreePlanException.');
        } catch (MissingFreePlanException $exception) {
            $this->assertStringContainsString('PlanSeeder', $exception->getMessage());
        }

        $this->assertSame(0, $this->subscriptionCountOf($organization));
    }

    public function test_a_second_subscription_is_a_domain_exception(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->action()->handle($organization);

        try {
            $this->action()->handle($organization);

            $this->fail('Se esperaba OrganizationAlreadySubscribedException.');
        } catch (OrganizationAlreadySubscribedException $exception) {
            $this->assertStringContainsString('exactamente una', $exception->getMessage());
        }

        $this->assertSame(1, $this->subscriptionCountOf($organization));
    }

    public function test_the_database_also_refuses_a_second_subscription(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->action()->handle($organization);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->withinOrganization($organization, fn (): Subscription => Subscription::factory()->create());
    }

    public function test_a_rolled_back_registration_leaves_no_subscription(): void
    {
        $organization = $this->organizationWithNeighbour();

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($organization): void {
                $this->action()->handle($organization);

                throw new \RuntimeException('el registro falló después del alta');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('el registro falló después del alta', $exception->getMessage());
        }

        $this->assertSame(0, $this->subscriptionCountOf($organization));
        $this->assertSame(0, $this->counterCountOf($organization));
    }

    private function action(): StartFreeSubscriptionAction
    {
        return app(StartFreeSubscriptionAction::class);
    }

    private function organizationWithNeighbour(): Organization
    {
        $organization = Organization::factory()->create();

        $this->neighbourOrganization();
        $this->withoutOrganization();

        return $organization;
    }

    private function subscriptionCountOf(Organization $organization): int
    {
        return $this->withinOrganization(
            $organization,
            fn (): int => Subscription::query()->count(),
        );
    }

    private function counterCountOf(Organization $organization): int
    {
        return $this->withinOrganization(
            $organization,
            fn (): int => UsageCounter::query()->count(),
        );
    }
}
