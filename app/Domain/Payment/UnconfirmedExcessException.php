<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use RuntimeException;

final class UnconfirmedExcessException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct(public readonly Money $unallocated)
    {
        parent::__construct(sprintf(
            'El pago excede el saldo del apartado en %s, que quedaría sin asignar y no se devuelve como crédito. Confírmalo para aprobarlo así.',
            MoneyFormatter::format($unallocated),
        ));
    }
}
