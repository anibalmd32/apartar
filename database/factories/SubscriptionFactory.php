<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Subscription> */
    protected $model = Subscription::class;

    /**
     * @return array<model-property<Subscription>, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => null,
            'grace_ends_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->startOfMonth()->addMonth(),
        ]);
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn (): array => ['plan_id' => $plan->getKey()]);
    }

    public function pastDue(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->addDays(7),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => SubscriptionStatus::Suspended]);
    }
}
