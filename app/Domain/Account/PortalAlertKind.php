<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum PortalAlertKind: string
{
    case DownPaymentWindow = 'down_payment_window';
    case Overdue = 'overdue';
    case ReceiptRejected = 'receipt_rejected';
    case DueSoon = 'due_soon';
    case ReceiptPending = 'receipt_pending';
    case ClaimAvailable = 'claim_available';

    public function priority(): int
    {
        return match ($this) {
            self::DownPaymentWindow => 1,
            self::Overdue => 2,
            self::ReceiptRejected => 3,
            self::DueSoon => 4,
            self::ReceiptPending => 5,
            self::ClaimAvailable => 6,
        };
    }
}
