<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Actions\Organization\ReactivateOrganizationAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\DowngradeOutcome;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantAlreadySetException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ApplyDowngradeAction
{
    use LogsSensitiveActivity;

    public const PROCESS = 'subscriptions:apply-downgrades';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly UsageCalculator $usage,
        private readonly ReactivateOrganizationAction $reactivate,
    ) {}

    /**
     * @throws TenantAlreadySetException
     */
    public function handle(Subscription $subscription): DowngradeOutcome
    {
        $organization = Organization::query()->findOrFail($subscription->organization_id);

        if ($this->context->hasOrganization() && $this->context->organizationId() !== (int) $organization->getKey()) {
            throw TenantAlreadySetException::cannotSwitch($this->context->organizationId(), (int) $organization->getKey());
        }

        return $this->context->runFor($organization, fn (): DowngradeOutcome => DB::transaction(function () use ($organization): DowngradeOutcome {
            /** @var Subscription $locked */
            $locked = Subscription::query()->with(['plan', 'scheduledPlan'])->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($locked->status === SubscriptionStatus::Cancelled) {
                return DowngradeOutcome::nothing();
            }

            $from = null;
            $to = null;

            if ($locked->scheduledPlan instanceof Plan && $locked->scheduled_plan_at !== null && $locked->scheduled_plan_at->lessThanOrEqualTo($now)) {
                $from = $locked->plan->code;
                $to = $locked->scheduledPlan->code;
                $this->apply($locked, $locked->scheduledPlan, $organization, $now);
            }

            $unpublished = [];
            $appliedAt = $locked->downgrade_applied_at?->toImmutable();

            if ($appliedAt !== null && DowngradeOutcome::unpublishDateFor($appliedAt)->lessThanOrEqualTo($now)) {
                $unpublished = $this->unpublishExcess($locked->plan);
                $locked->forceFill(['downgrade_applied_at' => null])->save();
                $appliedAt = null;
            }

            $outcome = new DowngradeOutcome($from, $to, $unpublished, $appliedAt === null ? null : DowngradeOutcome::unpublishDateFor($appliedAt));

            if (! $outcome->isNoop()) {
                $this->audit(
                    AuditEvent::SubscriptionDowngradeApplied,
                    subject: $locked,
                    after: [
                        'from_plan' => $from,
                        'to_plan' => $to,
                        'unpublished_catalogs' => $unpublished,
                        'unpublish_at' => $outcome->unpublishAt?->toIso8601String(),
                    ],
                    process: self::PROCESS,
                );
            }

            return $outcome;
        }));
    }

    private function apply(Subscription $subscription, Plan $target, Organization $organization, CarbonImmutable $now): void
    {
        $free = $target->price_cents <= 0;

        $subscription->forceFill([
            'plan_id' => $target->getKey(),
            'scheduled_plan_id' => null,
            'scheduled_plan_at' => null,
            'downgrade_applied_at' => $subscription->downgrade_applied_at !== null && $subscription->downgrade_applied_at->lessThan($now)
                ? $subscription->downgrade_applied_at
                : $now,
            'current_period_end' => $free ? null : $subscription->current_period_end,
            'current_period_start' => $free ? $now : $subscription->current_period_start,
            'status' => match (true) {
                $subscription->status === SubscriptionStatus::Grace => SubscriptionStatus::Active,
                $free && $subscription->status === SubscriptionStatus::PastDue => SubscriptionStatus::Active,
                default => $subscription->status,
            },
            'grace_ends_at' => $free ? null : $subscription->grace_ends_at,
        ])->save();

        $subscription->setRelation('plan', $target);

        if ($free) {
            $this->reactivate->handle($organization, null, self::PROCESS);
            $subscription->refresh()->setRelation('plan', $target);
        }
    }

    /**
     * @return list<string>
     */
    private function unpublishExcess(Plan $plan): array
    {
        $limit = $plan->limitFor(UsageMetric::Catalogs);

        if ($limit === null) {
            return [];
        }

        $this->limits->lockCounter(UsageMetric::Catalogs);
        $now = CarbonImmutable::now();

        $counting = Catalog::query()
            ->whereNotNull('published_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->orderBy('published_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'slug']);

        $excess = $counting->slice($limit)->values();

        if ($excess->isNotEmpty()) {
            Catalog::query()->whereKey($excess->modelKeys())->update(['published_at' => null]);
        }

        $this->usage->recountLocked(UsageMetric::Catalogs);

        return $excess->pluck('slug')->map(fn (mixed $slug): string => (string) $slug)->values()->all();
    }
}
