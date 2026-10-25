<?php

declare(strict_types=1);

namespace App\Domain\Money;

enum CurrencyDisplay: string
{
    public const COOKIE = 'currency_display';

    public const COOKIE_DAYS = 365;

    case UsdPrimary = 'usd_primary';

    case VesPrimary = 'ves_primary';

    public static function platformDefault(): self
    {
        return self::UsdPrimary;
    }

    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $display): string => $display->value, self::cases());
    }
}
