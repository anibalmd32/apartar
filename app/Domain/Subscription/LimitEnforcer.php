<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\PlanLimitRejection;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

final class LimitEnforcer
{
    /**
     * @throws LogicException
     * @throws CreationSuspendedException
     * @throws PlanLimitReachedException
     */
    public function assertCanCreate(UsageMetric $metric, int $amount = 1): void
    {
        $this->assertInsideTransaction('assertCanCreate', $metric);

        $subscription = $this->subscription();

        if (! $subscription->status->allowsCreation()) {
            throw CreationSuspendedException::whileCreating($metric);
        }

        $plan = $subscription->plan;
        $limit = $plan->limitFor($metric);

        if ($limit === null) {
            return;
        }

        $used = $this->lockedUsage($metric);

        if ($used + $amount > $limit) {
            throw PlanLimitReachedException::of(
                metric: $metric,
                used: $used,
                limit: $limit,
                amount: $amount,
                current: $plan,
                minimum: $this->cheapestPlanAdmitting($metric, $used + $amount),
            );
        }
    }

    /**
     * @throws CreationSuspendedException
     * @throws CatalogProductLimitReachedException
     */
    public function assertCatalogHolds(int $current, int $requested, bool $addsNew = true): void
    {
        if (! $addsNew && $requested <= $current) {
            return;
        }

        $subscription = $this->subscription();

        if (! $subscription->status->allowsCreation()) {
            throw CreationSuspendedException::whileCreating(UsageMetric::Products);
        }

        $plan = $subscription->plan;
        $limit = $plan->maxProductsPerCatalog();

        if ($limit === null || $requested <= $limit) {
            return;
        }

        throw CatalogProductLimitReachedException::of(
            used: $current,
            limit: $limit,
            requested: $requested,
            current: $plan,
            minimum: Plan::query()->public()->get()->first(
                fn (Plan $candidate): bool => $candidate->maxProductsPerCatalog() === null || $candidate->maxProductsPerCatalog() >= $requested,
            ),
        );
    }

    /**
     * @throws PlanFeatureUnavailableException
     */
    public function assertHasFeature(PlanFeature $feature): void
    {
        $plan = $this->subscription()->plan;

        if ($plan->hasFeature($feature)) {
            return;
        }

        throw PlanFeatureUnavailableException::of(
            feature: $feature,
            current: $plan,
            minimum: $this->cheapestPlanWith($feature),
        );
    }

    public function remaining(UsageMetric $metric): ?int
    {
        return $this->usageOf($metric)->remaining();
    }

    public function acceptsLayaways(): bool
    {
        $subscription = $this->subscription();

        if (! $subscription->status->allowsCreation()) {
            return false;
        }

        if (! $this->organizationIsActive()) {
            return false;
        }

        return $this->usageOf(UsageMetric::ActiveLayaways, $subscription)->admits(1);
    }

    public function offersLayaways(): bool
    {
        if ($this->acceptsLayaways()) {
            return true;
        }

        $subscription = $this->subscription();

        if ($subscription->status->allowsCreation() && $this->organizationIsActive()) {
            PlanLimitRejection::record(UsageMetric::ActiveLayaways);
        }

        return false;
    }

    private function organizationIsActive(): bool
    {
        return app(TenantContext::class)->organization()->status === OrganizationStatus::Active;
    }

    public function hasFeature(PlanFeature $feature): bool
    {
        return $this->plan()->hasFeature($feature);
    }

    public function plan(): Plan
    {
        return $this->subscription()->plan;
    }

    public function state(): PlanState
    {
        $subscription = $this->subscription();
        $plan = $subscription->plan;

        $values = UsageCounter::query()->pluck('value', 'metric');
        $public = Plan::query()->public()->get();
        $usage = [];
        $upgrades = [];

        foreach (UsageMetric::cases() as $metric) {
            $state = UsageState::of($metric, (int) ($values[$metric->value] ?? 0), $plan->limitFor($metric));
            $usage[$metric->value] = $state;

            if ($state->deservesWarning()) {
                $current = $plan->limitFor($metric);
                $upgrades[$metric->value] = $public->first(
                    fn (Plan $candidate): bool => $candidate->limitFor($metric) === null
                        || ($current !== null && $candidate->limitFor($metric) > $current),
                );
            }
        }

        $featureUpgrades = [];

        foreach (PlanFeature::cases() as $feature) {
            if (! $plan->hasFeature($feature)) {
                $featureUpgrades[$feature->value] = $public->first(fn (Plan $candidate): bool => $candidate->hasFeature($feature));
            }
        }

        $plansOffered = $public->contains(fn (Plan $candidate): bool => ! $candidate->is($plan));

        return PlanState::of($subscription, $plan, $usage, $upgrades, $featureUpgrades, $plansOffered);
    }

    public function usageOf(UsageMetric $metric, ?Subscription $subscription = null): UsageState
    {
        $subscription ??= $this->subscription();

        return UsageState::of(
            $metric,
            $this->materialisedUsage($metric),
            $subscription->plan->limitFor($metric),
        );
    }

    /**
     * @throws LogicException
     */
    public function adjust(UsageMetric $metric, int $delta): int
    {
        $this->assertInsideTransaction('adjust', $metric);

        if ($metric->isDerived()) {
            throw new LogicException(sprintf(
                'adjust(%s): esa métrica es derivada y se recalcula por agregación, no por incremento.',
                $metric->value,
            ));
        }

        $counter = $this->counterFor($metric, locked: true);
        $wanted = $counter->value + $delta;
        $value = max(0, $wanted);

        if ($wanted < 0) {
            Log::warning('usage_counter.below_zero', [
                'metric' => $metric->value,
                'organization_id' => $counter->organization_id,
                'previous' => $counter->value,
                'delta' => $delta,
            ]);
        }

        if ($value !== $counter->value) {
            $counter->forceFill(['value' => $value, 'computed_at' => now()])->save();
        }

        return $value;
    }

    /**
     * @throws LogicException
     */
    public function lockCounter(UsageMetric $metric): void
    {
        $this->assertInsideTransaction('lockCounter', $metric);

        $this->counterFor($metric, locked: true);
    }

    private function lockedUsage(UsageMetric $metric): int
    {
        return $this->counterFor($metric, locked: true)->value;
    }

    private function materialisedUsage(UsageMetric $metric): int
    {
        return (int) (UsageCounter::query()->where('metric', $metric)->value('value') ?? 0);
    }

    private function counterFor(UsageMetric $metric, bool $locked = false): UsageCounter
    {
        $query = UsageCounter::query()->where('metric', $metric);

        if ($locked) {
            $query->lockForUpdate();
        }

        $counter = $query->first();

        if ($counter instanceof UsageCounter) {
            return $counter;
        }

        return UsageCounter::query()->create([
            'metric' => $metric,
            'value' => 0,
            'computed_at' => now(),
        ]);
    }

    private function subscription(): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->with('plan')->firstOrFail();

        return $subscription;
    }

    private function cheapestPlanAdmitting(UsageMetric $metric, int $needed): ?Plan
    {
        return Plan::query()->public()->get()->first(
            fn (Plan $plan): bool => $plan->limitFor($metric) === null || $plan->limitFor($metric) >= $needed,
        );
    }

    private function cheapestPlanWith(PlanFeature $feature): ?Plan
    {
        return Plan::query()->public()->get()->first(
            fn (Plan $plan): bool => $plan->hasFeature($feature),
        );
    }

    /**
     * @throws LogicException
     */
    private function assertInsideTransaction(string $operation, UsageMetric $metric): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        throw new LogicException(sprintf(
            '%s(%s) se llamó fuera de una transacción. El contador se lee BLOQUEADO '
            .'dentro de la transacción que lo mueve ([05 §5]): comprobar o mover en una transacción y escribir '
            .'en otra deja pasar dos operaciones simultáneas con un solo cupo. Envuelve la acción '
            .'en DB::transaction().',
            $operation,
            $metric->value,
        ));
    }
}
