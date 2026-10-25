<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Payment\PaymentAccountView;
use App\Models\PaymentAccount;

final class ActivePaymentAccountsQuery
{
    /**
     * @return list<PaymentAccountView>
     */
    public function all(): array
    {
        return PaymentAccount::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentAccount $account): PaymentAccountView => PaymentAccountView::of($account))
            ->values()
            ->all();
    }
}
