<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Money;

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public static function resolve(Money $amount, Money $paid, bool $pastGrace): self
    {
        if ($paid->cents >= $amount->cents) {
            return self::Paid;
        }

        if ($pastGrace) {
            return self::Overdue;
        }

        return $paid->cents > 0 ? self::PartiallyPaid : self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::PartiallyPaid => 'Pago parcial',
            self::Paid => 'Pagada',
            self::Overdue => 'En mora',
        };
    }
}
