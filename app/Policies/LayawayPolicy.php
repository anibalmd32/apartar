<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Layaway;
use App\Models\User;

final class LayawayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->disabled_at === null && $user->role->can(OrganizationCapability::Collections);
    }

    public function create(User $user): bool
    {
        return $user->disabled_at === null && $user->role->can(OrganizationCapability::Collections);
    }

    public function manage(User $user, Layaway $layaway): bool
    {
        return $user->organization_id === (int) $layaway->organization_id
            && $user->role->can(OrganizationCapability::Collections);
    }
}
