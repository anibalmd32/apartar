<?php

declare(strict_types=1);

namespace App\Domain\Money;

final class MoneyFormatter
{
    public static function format(Money $money): string
    {
        $absolute = abs($money->cents);
        $units = intdiv($absolute, 100);
        $fraction = $absolute % 100;
        $sign = $money->cents < 0 ? '-' : '';

        return match ($money->currency) {
            Currency::USD => sprintf('%s$%s,%02d', $sign, self::group($units, '.'), $fraction),
            Currency::VES => sprintf('%sBs. %s,%02d', $sign, self::group($units, '.'), $fraction),
        };
    }

    private static function group(int $units, string $separator): string
    {
        $digits = (string) $units;

        return strrev(implode($separator, str_split(strrev($digits), 3)));
    }
}
