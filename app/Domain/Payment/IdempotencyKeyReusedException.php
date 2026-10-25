<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use RuntimeException;

final class IdempotencyKeyReusedException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct()
    {
        parent::__construct('Este formulario ya registró otro pago. Recarga la página para registrar uno nuevo.');
    }
}
