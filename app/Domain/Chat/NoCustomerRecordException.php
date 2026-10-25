<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use DomainException;

final class NoCustomerRecordException extends DomainException
{
    public const STATUS = 403;

    public const MESSAGE = 'Para escribirle a este negocio, aparta algo o entra con tu cédula y el código de tu apartado.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
