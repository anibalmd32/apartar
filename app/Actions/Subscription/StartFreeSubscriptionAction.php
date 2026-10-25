<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Subscription\MissingFreePlanException;
use App\Domain\Subscription\OrganizationAlreadySubscribedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class StartFreeSubscriptionAction
{
    public const FREE_PLAN_CODE = 'free';

    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageCalculator $usage,
    ) {}

    public static function freePlan(): ?Plan
    {
        return Plan::query()->where('code', self::FREE_PLAN_CODE)->first();
    }

    /**
     * @throws MissingFreePlanException
     * @throws OrganizationAlreadySubscribedException
     */
    public function handle(Organization $organization): Subscription
    {
        $plan = self::freePlan();

        if (! $plan instanceof Plan) {
            throw MissingFreePlanException::withCode(self::FREE_PLAN_CODE);
        }

        return $this->context->runFor($organization, function () use ($organization, $plan): Subscription {
            if (Subscription::query()->exists()) {
                throw OrganizationAlreadySubscribedException::for((int) $organization->getKey());
            }

            return DB::transaction(function () use ($plan): Subscription {
                $startedAt = now();

                $subscription = Subscription::query()->create([
                    'plan_id' => $plan->getKey(),
                    'status' => SubscriptionStatus::Active,
                    'started_at' => $startedAt,
                    'current_period_start' => $startedAt,
                    'current_period_end' => null,
                    'grace_ends_at' => null,
                    'cancelled_at' => null,
                ]);

                $this->initialiseCounters($startedAt);

                return $subscription;
            });
        });
    }

    private function initialiseCounters(DateTimeInterface $computedAt): void
    {
        foreach (UsageMetric::cases() as $metric) {
            UsageCounter::query()->create([
                'metric' => $metric,
                'value' => $this->usage->measure($metric),
                'computed_at' => $computedAt,
            ]);
        }
    }
}
