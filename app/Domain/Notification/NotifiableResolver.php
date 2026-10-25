<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Models\Account;
use App\Models\Customer;

final class NotifiableResolver
{
    public function for(Customer $customer): Account|Customer
    {
        if ($customer->account_id === null) {
            return $customer;
        }

        $account = Account::query()->find($customer->account_id);

        if (! $account instanceof Account) {
            return $customer;
        }

        if ($account->pushSubscriptions()->exists()) {
            $customer->pushSubscriptions()->delete();
        }

        return $account;
    }
}
