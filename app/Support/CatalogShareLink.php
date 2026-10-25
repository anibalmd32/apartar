<?php

declare(strict_types=1);

namespace App\Support;

final class CatalogShareLink
{
    public const PREFIX = 'c';

    public const ROUTE = 'catalog.show';

    public const PRODUCT_ROUTE = 'catalog.product';

    public const STOREFRONT_ROUTE = 'storefront';

    public static function storefrontOf(string $organizationSlug): string
    {
        return route(self::STOREFRONT_ROUTE, ['organization' => $organizationSlug]);
    }

    public static function of(string $slug): string
    {
        return route(self::ROUTE, ['catalog' => $slug]);
    }
}
