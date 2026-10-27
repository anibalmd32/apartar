<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class NewArrival
{
    public const DAYS = 14;

    public const ENTERED_SQL = 'GREATEST(`catalog_product`.`created_at`, `products`.`published_at`)';

    public static function enteredAt(?CarbonInterface $published, ?CarbonInterface $addedToCatalog): ?CarbonImmutable
    {
        if ($published === null || $addedToCatalog === null) {
            return null;
        }

        return CarbonImmutable::instance($published->max($addedToCatalog));
    }

    public static function since(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? CarbonImmutable::now())->subDays(self::DAYS);
    }

    public static function isNew(?CarbonInterface $enteredAt, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        return $enteredAt !== null && $enteredAt->lte($now) && $enteredAt->gte(self::since($now));
    }
}
