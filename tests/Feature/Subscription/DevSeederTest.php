<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\ChangePlanAction;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DevSeeder;
use Database\Seeders\PlanSeeder;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DevSeederTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->organization = Organization::factory()->create(['document' => 'J-412345678']);

        $this->owner = $this->withinOrganization($this->organization, function (): User {
            $free = Plan::query()->where('code', 'free')->sole();

            Subscription::factory()->forPlan($free)->create();

            return User::factory()->owner()->create();
        });

        $this->withoutOrganization();
    }

    public function test_the_demo_scenario_publishes_the_paid_plans(): void
    {
        $this->assertSame(['free'], Plan::query()->public()->pluck('code')->all());

        $this->seed(DevSeeder::class);

        $this->assertSame(
            ['free', 'pro', 'business'],
            Plan::query()->public()->orderBy('position')->pluck('code')->all(),
        );
    }

    public function test_the_demo_scenario_upgrades_a_business_to_a_paid_plan(): void
    {
        $this->seed(DevSeeder::class);

        $pro = Plan::query()->where('code', 'pro')->sole();

        $subscription = app(ChangePlanAction::class)->handle($this->organization, $pro, $this->owner);

        $this->assertSame('pro', $subscription->plan->code);
    }
}
