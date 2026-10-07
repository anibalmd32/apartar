<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use RuntimeException;
use Throwable;

/**
 * El comprobante ya se había reportado **en esa organización**
 * (`REQ-PAY-003` RB-1, SP-2).
 *
 * Es un bloqueo duro: un duplicado nunca llega a la bandeja del negocio. Lo
 * lanza `SubmitReceiptAction` tanto cuando lo encuentra al comprobar como
 * cuando gana la otra subida de una carrera y salta el único de la tabla
 * (SP-5); el formulario lo traduce a un `422` sobre el campo que dice
 * `field()`.
 *
 * ## El mensaje no dice nada más, a propósito
 *
 * RB-6: «este comprobante ya fue reportado», y punto. Ni de quién, ni cuándo,
 * ni en qué apartado: con la misma referencia en la misma organización puede
 * haber otro cliente (SP-2), y decirle a uno que otro ya pagó con esa
 * referencia es filtrarle un dato ajeno. El criterio viaja en la propiedad,
 * para quien lo necesite, y no en el texto.
 */
final class DuplicateReceiptException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'Este comprobante ya fue reportado.';

    public function __construct(
        public readonly DuplicateCriterion $criterion,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    /**
     * El campo del formulario al que pertenece el error: el archivo si
     * coincidió el hash, la referencia si coincidió la pareja.
     */
    public function field(): string
    {
        return match ($this->criterion) {
            DuplicateCriterion::FileHash => 'file',
            DuplicateCriterion::BankReference => 'reference',
        };
    }
}
