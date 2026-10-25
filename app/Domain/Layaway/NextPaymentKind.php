<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

enum NextPaymentKind: string
{
    case DownPayment = 'down_payment';
    case Installment = 'installment';
}
