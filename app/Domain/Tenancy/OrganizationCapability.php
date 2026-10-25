<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum OrganizationCapability: string
{
    case Subscription = 'subscription';

    case Billing = 'billing';

    case Team = 'team';

    case Settings = 'settings';

    case PaymentAccounts = 'payment_accounts';

    case Branding = 'branding';

    case Audit = 'audit';

    case Products = 'products';

    case Catalogs = 'catalogs';

    case Customers = 'customers';

    case Collections = 'collections';

    case Receipts = 'receipts';

    case Chat = 'chat';

    public function requiresOwner(): bool
    {
        return match ($this) {
            self::Subscription, self::Billing, self::Team,
            self::Settings, self::PaymentAccounts, self::Branding,
            self::Audit => true,

            self::Products, self::Catalogs, self::Customers,
            self::Collections, self::Receipts, self::Chat => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function ownerOnly(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $capability): bool => $capability->requiresOwner(),
        ));
    }

    /**
     * @return list<self>
     */
    public static function shared(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $capability): bool => ! $capability->requiresOwner(),
        ));
    }
}
