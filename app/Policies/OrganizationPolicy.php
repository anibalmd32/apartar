<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Organization;
use App\Models\User;

final class OrganizationPolicy
{
    public function viewSettings(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Settings);
    }

    public function updateSettings(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Settings);
    }

    public function managePaymentAccounts(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::PaymentAccounts);
    }

    public function manageBranding(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Branding);
    }

    public function viewAuditLog(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Audit);
    }

    public function operate(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization);
    }

    public function manageProducts(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Products);
    }

    public function close(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Subscription);
    }

    private function governs(User $user, Organization $organization, OrganizationCapability $capability): bool
    {
        return $this->belongsTo($user, $organization) && $user->role->can($capability);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organization_id === (int) $organization->getKey();
    }
}
