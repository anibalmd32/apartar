<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

final class ManualPaymentDates
{
    public const YEARS_BACK = 3;

    /**
     * @return array{0: string, 1: string}
     */
    public static function bounds(DateTimeZone|string $timezone): array
    {
        $today = CarbonImmutable::now($timezone);

        return [$today->subYears(self::YEARS_BACK)->toDateString(), $today->toDateString()];
    }
}
