<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * La regla de todo importe que entra por un pago: **un decimal positivo de
 * hasta dos cifras, en su moneda** (`REQ-PAY-001` RB-5, `REQ-PAY-005` SP-4,
 * `REQ-PAY-007` SP-2).
 *
 * **Antes de convertir.** Que lo convertido a dólares no sea cero
 * (`REQ-FX-006` SP-4: Bs. 0,01 a 42,50 da cero centavos) no se puede saber
 * aquí: depende de la tasa que se aplique, y esa la resuelve
 * `PaymentApplier` (`T-058`), que es quien tiene que rechazarlo.
 *
 * La comparten el comprobante, la aprobación y el pago manual, y por eso vive
 * en un sitio: tres copias de «mayor que cero» acaban siendo dos «mayor que
 * cero» y un «mayor o igual». La forma la decide `Money::fromDecimalString()`,
 * la misma frontera que cualquier otro importe escrito; aquí se añade que no
 * sea cero ni negativo, porque un pago de cero no existe.
 */
final class PaymentAmounts
{
    /**
     * @param  string  $what  qué importe es, para el mensaje: «declarado», «conciliado»…
     *
     * @throws InvalidArgumentException si no es un decimal positivo de hasta dos cifras
     */
    public static function assertPositive(string $amount, Currency $currency, string $what): Money
    {
        $money = Money::fromDecimalString($amount, $currency);

        if ($money->cents <= 0) {
            throw new InvalidArgumentException(sprintf('El importe %s tiene que ser mayor que cero, y es "%s".', $what, $amount));
        }

        return $money;
    }
}
