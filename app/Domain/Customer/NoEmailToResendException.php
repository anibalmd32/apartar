<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use RuntimeException;

final class NoEmailToResendException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct()
    {
        parent::__construct('Este cliente no tiene correo: copia el código y díctaselo por teléfono o por el chat.');
    }
}
