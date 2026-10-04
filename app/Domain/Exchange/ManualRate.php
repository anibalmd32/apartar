<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;

/**
 * La tasa que el negocio escribe a mano al conciliar un pago
 * (`REQ-FX-006` SP-3, `REQ-PAY-005` SP-7, `REQ-PAY-007`).
 *
 * Cuando el pago se recibió días antes, el negocio puede aplicar la tasa de
 * aquel día en vez de la vigente; el pago queda entonces con
 * `rate_source = manual` y la operación se audita. Lo que esta clase garantiza
 * es que esa tasa **se puede usar**: la misma comprobación que hace
 * `Money::convertTo()` —un decimal positivo, sin notación exponencial—, y
 * además **que quepa tal cual en `payments.rate_applied DECIMAL(18,8)`**:
 * como mucho ocho decimales y diez cifras enteras. Una novena cifra decimal no
 * daría error al guardar —MySQL la redondearía en silencio, y el pago diría
 * haber usado una tasa distinta de la que convirtió el importe—, y una
 * undécima entera reventaría el `INSERT` con un `500` en lugar del `422`.
 */
final readonly class ManualRate
{
    public const SCALE = 8;

    /** Las cifras enteras que caben en `DECIMAL(18,8)`: dieciocho menos ocho. */
    public const INTEGER_DIGITS = 10;

    /**
     * @throws InvalidRateException si no es un decimal positivo que quepa tal cual en `DECIMAL(18,8)`
     */
    public function __construct(public string $rate)
    {
        Money::assertUsableRate($rate);

        [$integer, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        if (strlen($fraction) > self::SCALE) {
            throw InvalidRateException::tooPrecise($rate, self::SCALE);
        }

        // Los ceros a la izquierda no ocupan sitio en la columna: `0042.5`
        // cabe igual que `42.5`.
        if (strlen(ltrim($integer, '0')) > self::INTEGER_DIGITS) {
            throw InvalidRateException::tooLarge($rate, self::INTEGER_DIGITS);
        }
    }
}
