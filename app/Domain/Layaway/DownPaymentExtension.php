<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

enum DownPaymentExtension: string
{
    case Granted = 'granted';

    case WindowStillOpen = 'window_still_open';

    case AlreadyExtended = 'already_extended';

    case NotAwaitingDownPayment = 'not_awaiting_down_payment';

    public function wasGranted(): bool
    {
        return $this === self::Granted;
    }
}
