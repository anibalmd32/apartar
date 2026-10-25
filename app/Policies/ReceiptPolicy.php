<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Customer;
use App\Models\Receipt;
use App\Models\User;

final class ReceiptPolicy
{
    public function view(User|Customer $actor, Receipt $receipt): bool
    {
        if ($actor->organization_id !== (int) $receipt->organization_id) {
            return false;
        }

        if ($actor instanceof Customer) {
            return $actor->id === (int) $receipt->customer_id;
        }

        return $actor->role->can(OrganizationCapability::Receipts);
    }

    public function viewAny(User|Customer $actor): bool
    {
        return $actor instanceof User && $actor->role->can(OrganizationCapability::Receipts);
    }

    public function review(User|Customer $actor, Receipt $receipt): bool
    {
        return $actor instanceof User
            && $actor->organization_id === (int) $receipt->organization_id
            && $actor->role->can(OrganizationCapability::Receipts);
    }
}
