<?php

declare(strict_types=1);

namespace App\Domain\Money;

enum Currency: string
{
    case USD = 'USD';

    case VES = 'VES';

    public function label(): string
    {
        return match ($this) {
            self::USD => 'Dólares (USD)',
            self::VES => 'Bolívares (VES)',
        };
    }
}
