<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class PaymentDay
{
    public const PARAMETER = 'paid_at';

    public static function fromQuery(Request $request, string $timezone): CarbonImmutable
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $raw = $request->query(self::PARAMETER);

        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1 || ! checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4))) {
            return $today;
        }

        $day = CarbonImmutable::parse($raw, $timezone)->startOfDay();

        return $day->greaterThan($today) ? $today : $day;
    }
}
