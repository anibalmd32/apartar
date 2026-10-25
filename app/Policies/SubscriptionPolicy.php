<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Subscription;
use App\Models\User;

final class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Subscription);
    }

    public function changePlan(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Subscription);
    }

    public function viewInvoices(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Billing);
    }

    public function uploadPaymentProof(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Billing);
    }

    private function governs(User $user, Subscription $subscription, OrganizationCapability $capability): bool
    {
        return $user->organization_id === $subscription->organization_id
            && $user->role->can($capability);
    }
}
