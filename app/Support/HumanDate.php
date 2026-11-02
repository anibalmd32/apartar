<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

final class HumanDate
{
    private const NBSP = "\u{00A0}";

    /**
     * @var list<string>
     */
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * @var list<string>
     */
    private const SHORT_MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

    public static function short(CarbonInterface $at): string
    {
        return sprintf('%d %s %d', $at->day, self::SHORT_MONTHS[$at->month - 1], $at->year);
    }

    public static function long(CarbonInterface $at): string
    {
        return sprintf('%d de %s de %d', $at->day, self::MONTHS[$at->month - 1], $at->year);
    }

    public static function dayMonth(CarbonInterface $at): string
    {
        return sprintf('%d %s', $at->day, self::SHORT_MONTHS[$at->month - 1]);
    }

    public static function numeric(CarbonInterface $at): string
    {
        return $at->format('d/m/Y');
    }

    public static function time(CarbonInterface $at): string
    {
        $meridiem = $at->hour < 12 ? 'a.'.self::NBSP.'m.' : 'p.'.self::NBSP.'m.';

        return $at->format('g:i').self::NBSP.$meridiem;
    }

    public static function dateTime(CarbonInterface $at): string
    {
        return self::short($at).', '.self::time($at);
    }

    public static function longDateTime(CarbonInterface $at): string
    {
        return self::long($at).', '.self::time($at);
    }
}
