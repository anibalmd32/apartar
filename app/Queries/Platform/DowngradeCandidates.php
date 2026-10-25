<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Subscription\DowngradeOutcome;
use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

final class DowngradeCandidates
{
    /**
     * @return list<int>
     */
    public function organizationIds(CarbonImmutable $now): array
    {
        return Subscription::query()
            ->withoutTenantScope()
            ->where('status', '!=', SubscriptionStatus::Cancelled)
            ->where(fn ($query) => $query
                ->where(fn ($due) => $due->whereNotNull('scheduled_plan_id')->where('scheduled_plan_at', '<=', $now))
                ->orWhere('downgrade_applied_at', '<=', $now->subDays(DowngradeOutcome::graceDays())))
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
