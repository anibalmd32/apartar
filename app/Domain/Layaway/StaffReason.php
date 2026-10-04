<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use InvalidArgumentException;

/**
 * El motivo que escribe el negocio al cancelar un apartado o reprogramar una
 * cuota (`REQ-LAY-009` RB-2, `REQ-LAY-011` RB-4): de 10 a 500 caracteres, sin
 * contar los espacios de los bordes. Desde `T-055` es también la nota
 * obligatoria de un pago manual (`REQ-PAY-007` RB-1), y será el motivo de una
 * reasignación (`REQ-PAY-009` RB-5): las cuatro son la misma regla.
 *
 * Las cuatro operaciones se auditan con él, y un motivo de tres letras no deja
 * constancia de nada. El formulario ya lo valida y responde `422`; esto es lo
 * que impide que una llamada que se salte el formulario —un job, un comando—
 * escriba uno vacío: un motivo inválido **no se puede construir**.
 *
 * **Quién lo lee depende de la operación, no de esta clase.** El motivo de
 * una cancelación es interno y no llega nunca al cliente (`REQ-LAY-009`
 * RB-8). La nota de un pago manual, en cambio, **sí** la ve el cliente en su
 * historial, junto a «registrado por el negocio» (`REQ-LAY-022` RB-7,
 * `REQ-PAY-007` RB-5).
 */
final readonly class StaffReason
{
    public const MIN = 10;

    public const MAX = 500;

    public string $text;

    public function __construct(string $text)
    {
        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN || $length > self::MAX) {
            throw new InvalidArgumentException(sprintf(
                'El motivo tiene que tener entre %d y %d caracteres, y tiene %d.',
                self::MIN,
                self::MAX,
                $length,
            ));
        }

        $this->text = $trimmed;
    }
}
