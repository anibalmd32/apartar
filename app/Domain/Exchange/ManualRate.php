<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;

final readonly class ManualRate
{
    public const SCALE = 8;

    public const INTEGER_DIGITS = 10;

    /**
     * @throws InvalidRateException
     */
    public function __construct(public string $rate)
    {
        Money::assertUsableRate($rate);

        [$integer, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        if (strlen($fraction) > self::SCALE) {
            throw InvalidRateException::tooPrecise($rate, self::SCALE);
        }

        if (strlen(ltrim($integer, '0')) > self::INTEGER_DIGITS) {
            throw InvalidRateException::tooLarge($rate, self::INTEGER_DIGITS);
        }
    }
}
