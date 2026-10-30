<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Customer;
use App\Models\User;

final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->disabled_at === null && $user->role->can(OrganizationCapability::Customers);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user) && $user->organization_id === (int) $customer->organization_id;
    }

    public function merge(User $user, Customer $survivor, Customer $absorbed): bool
    {
        return $this->view($user, $survivor) && $user->organization_id === (int) $absorbed->organization_id;
    }
}
