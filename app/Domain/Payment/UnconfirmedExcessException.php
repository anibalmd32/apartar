<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use RuntimeException;

/**
 * Un pago que excede el saldo y nadie confirmó el sobrante (`REQ-PAY-005`
 * SP-5).
 *
 * El excedente no es crédito (RB-6): queda en `unallocated_cents` y no vuelve
 * a contar nunca. Que eso pase sin que quien aprueba lo haya visto es como se
 * pierde dinero de un cliente sin que nadie se entere, así que la pantalla lo
 * pide **y el servidor también**: una petición sin la confirmación se rechaza
 * después de calcular el reparto —que es cuando se sabe cuánto sobra— y la
 * transacción entera se deshace.
 */
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
