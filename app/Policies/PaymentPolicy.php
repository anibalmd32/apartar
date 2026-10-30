<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;

final class PaymentPolicy
{
    public function viewAttachment(User|Customer $actor, Payment $payment): bool
    {
        if ($actor->organization_id !== (int) $payment->organization_id || ! $payment->hasAttachment()) {
            return false;
        }

        if ($actor instanceof Customer) {
            return $actor->id === (int) Layaway::query()->whereKey($payment->layaway_id)->value('customer_id');
        }

        return $actor->role->can(OrganizationCapability::Collections)
            || $actor->role->can(OrganizationCapability::Receipts)
            || $actor->role->can(OrganizationCapability::Customers);
    }
}
