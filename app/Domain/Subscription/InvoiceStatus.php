<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum InvoiceStatus: string
{
    case Pending = 'pending';

    case UnderReview = 'under_review';

    case Paid = 'paid';

    case Overdue = 'overdue';

    case Void = 'void';

    public function acceptsReceipts(): bool
    {
        return match ($this) {
            self::Pending, self::UnderReview, self::Overdue => true,
            self::Paid, self::Void => false,
        };
    }

    public function isOutstanding(): bool
    {
        return $this->acceptsReceipts();
    }
}
