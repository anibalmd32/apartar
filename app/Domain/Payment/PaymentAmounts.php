<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use InvalidArgumentException;

final class PaymentAmounts
{
    public const INTEGER_DIGITS = 14;

    /**
     * @throws InvalidArgumentException
     */
    public static function assertPositive(string $amount, Currency $currency, string $what): Money
    {
        $money = Money::fromDecimalString($amount, $currency);

        if ($money->cents <= 0) {
            throw new InvalidArgumentException(sprintf('El importe %s tiene que ser mayor que cero, y es "%s".', $what, $amount));
        }

        $integer = ltrim(explode('.', ltrim($amount, '-'), 2)[0], '0');

        if (strlen($integer) > self::INTEGER_DIGITS) {
            throw new InvalidArgumentException(sprintf(
                'El importe %s tiene como mucho %d cifras antes de los decimales, y "%s" tiene %d.',
                $what,
                self::INTEGER_DIGITS,
                $amount,
                strlen($integer),
            ));
        }

        return $money;
    }
}
