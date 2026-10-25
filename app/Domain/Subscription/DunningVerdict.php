<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum DunningVerdict: string
{
    case Settled = 'settled';

    case PastDue = 'past_due';

    case Suspended = 'suspended';

    case Untouched = 'untouched';
}
