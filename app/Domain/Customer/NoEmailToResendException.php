<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use RuntimeException;

/**
 * Reenviar el código de un apartado a una ficha sin correo (`REQ-BOF-041`
 * SP-7): no hay a dónde mandarlo. La pantalla ofrece copiarlo para dictarlo.
 */
final class NoEmailToResendException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct()
    {
        parent::__construct('Este cliente no tiene correo: copia el código y díctaselo por teléfono o por el chat.');
    }
}
