<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum OrganizationRole: string
{
    case Owner = 'owner';

    case Member = 'member';

    public function can(OrganizationCapability $capability): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Member => ! $capability->requiresOwner(),
        };
    }

    /**
     * @return list<OrganizationCapability>
     */
    public function capabilities(): array
    {
        return array_values(array_filter(
            OrganizationCapability::cases(),
            fn (OrganizationCapability $capability): bool => $this->can($capability),
        ));
    }

    public function canManageBilling(): bool
    {
        return $this->can(OrganizationCapability::Subscription)
            && $this->can(OrganizationCapability::Billing);
    }

    public function canManageTeam(): bool
    {
        return $this->can(OrganizationCapability::Team);
    }

    public function canManageSettings(): bool
    {
        return $this->can(OrganizationCapability::Settings)
            && $this->can(OrganizationCapability::PaymentAccounts)
            && $this->can(OrganizationCapability::Branding);
    }
}
