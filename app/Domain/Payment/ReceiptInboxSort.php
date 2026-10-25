<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Support\SortWhitelist;

enum ReceiptInboxSort: string
{
    case Submitted = 'submitted';

    case PaidAt = 'paid_at';

    public function column(): string
    {
        return match ($this) {
            self::Submitted => 'receipts.created_at',
            self::PaidAt => 'receipts.paid_at',
        };
    }

    /**
     * @return SortWhitelist<self>
     */
    public static function whitelist(): SortWhitelist
    {
        return new SortWhitelist(self::cases());
    }
}
