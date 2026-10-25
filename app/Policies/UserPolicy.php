<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Team);
    }

    public function view(User $user, User $member): bool
    {
        return $this->governsTeamOf($user, $member);
    }

    public function create(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Team);
    }

    public function update(User $user, User $member): bool
    {
        return $this->governsTeamOf($user, $member);
    }

    private function governsTeamOf(User $user, User $member): bool
    {
        return $user->organization_id === $member->organization_id
            && $user->role->can(OrganizationCapability::Team);
    }
}
