<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Receipt;

class RecordedReceipts
{
    public function existFor(PaymentAccount $account): bool
    {
        return Receipt::query()->where('payment_account_id', $account->getKey())->exists()
            || Payment::query()->where('payment_account_id', $account->getKey())->exists();
    }
}
