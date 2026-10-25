<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum SubscriptionStatus: string
{
    case Active = 'active';

    case PastDue = 'past_due';

    case Grace = 'grace';

    case Suspended = 'suspended';

    case Cancelled = 'cancelled';

    public function allowsTeamAccess(): bool
    {
        return match ($this) {
            self::Active, self::PastDue, self::Grace, self::Suspended => true,
            self::Cancelled => false,
        };
    }

    public function allowsCreation(): bool
    {
        return match ($this) {
            self::Active, self::PastDue, self::Grace => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    public function isDelinquent(): bool
    {
        return $this === self::PastDue || $this === self::Suspended;
    }
}
