<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Money\Money;

final class Savings
{
    /**
     * @return int|null the rounded percent the buyer saves against the "before" price, or null when there is no saving.
     */
    public static function percent(Money $price, ?Money $before): ?int
    {
        if ($before === null || $before->cents <= $price->cents) {
            return null;
        }

        return (int) round(100 - ($price->cents * 100 / $before->cents));
    }
}
