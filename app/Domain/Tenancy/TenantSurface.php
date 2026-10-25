<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum TenantSurface: string
{
    case Backoffice = 'backoffice';

    case PublicCatalog = 'public_catalog';

    case Storefront = 'storefront';

    case Portal = 'portal';

    case Platform = 'platform';

    public function requiresTenant(): bool
    {
        return $this !== self::Platform;
    }
}
