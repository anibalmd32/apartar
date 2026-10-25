<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Models\SupportAccessGrant;
use Carbon\CarbonImmutable;

final class SupportGrants
{
    public function activeFor(int $platformUserId, int $organizationId, ?CarbonImmutable $now = null): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()->withoutTenantScope()
            ->where('platform_user_id', $platformUserId)
            ->where('organization_id', $organizationId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now ?? CarbonImmutable::now())
            ->latest('expires_at')
            ->first();
    }

    /**
     * @param  list<int>  $organizationIds
     * @return list<int>
     */
    public function organizationsWithActiveGrant(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return SupportAccessGrant::query()->withoutTenantScope()
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->distinct()
            ->pluck('organization_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<SupportAccessGrant>
     */
    public function recentFor(int $organizationId, int $limit = 10): array
    {
        return SupportAccessGrant::query()->withoutTenantScope()
            ->with('operator:id,name')
            ->where('organization_id', $organizationId)
            ->latest('granted_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function find(int $grantId): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()->withoutTenantScope()->find($grantId);
    }
}
