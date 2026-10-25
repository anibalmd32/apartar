<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum OrganizationStatus: string
{
    case Active = 'active';

    case Suspended = 'suspended';

    case Closed = 'closed';

    public function allowsTeamAccess(): bool
    {
        return match ($this) {
            self::Active, self::Suspended => true,
            self::Closed => false,
        };
    }

    public function allowsCustomerAccess(): bool
    {
        return match ($this) {
            self::Active, self::Suspended, self::Closed => true,
        };
    }

    public function acceptsCustomerReceipts(): bool
    {
        return match ($this) {
            self::Active, self::Suspended => true,
            self::Closed => false,
        };
    }
}
