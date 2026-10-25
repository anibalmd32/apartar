<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;

final class DunningCandidates
{
    /**
     * @return list<int>
     */
    public function organizationIds(): array
    {
        $owing = SubscriptionInvoice::query()
            ->withoutTenantScope()
            ->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::UnderReview])
            ->distinct()
            ->pluck('organization_id');

        $delinquent = Subscription::query()
            ->withoutTenantScope()
            ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended])
            ->pluck('organization_id');

        $suspended = Organization::query()
            ->where('status', OrganizationStatus::Suspended)
            ->pluck('id');

        $ids = $owing->merge($delinquent)->merge($suspended)->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return Organization::query()
            ->whereKey($ids)
            ->where('status', '!=', OrganizationStatus::Closed)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
