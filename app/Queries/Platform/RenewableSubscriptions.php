<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Models\Subscription;
use Carbon\CarbonImmutable;

final class RenewableSubscriptions
{
    public const DAYS_AHEAD = 7;

    /**
     * @return list<int>
     */
    public function organizationIds(CarbonImmutable $now): array
    {
        return Subscription::query()
            ->withoutTenantScope()
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now->addDays(self::DAYS_AHEAD + 1))
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
