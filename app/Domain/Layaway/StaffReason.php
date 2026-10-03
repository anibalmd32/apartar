<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use InvalidArgumentException;

/**
 * El motivo que escribe el negocio al cancelar un apartado o reprogramar una
 * cuota (`REQ-LAY-009` RB-2, `REQ-LAY-011` RB-4): de 10 a 500 caracteres, sin
 * contar los espacios de los bordes.
 *
 * Las dos operaciones se auditan con él, y un motivo de tres letras no deja
 * constancia de nada. El formulario ya lo valida y responde `422` (SP-2);
 * esto es lo que impide que una llamada que se salte el formulario —un job,
 * un comando— escriba uno vacío: un motivo inválido **no se puede construir**.
 *
 * Es interno del negocio. El de una cancelación no llega nunca al cliente
 * (`REQ-LAY-009` RB-8).
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
