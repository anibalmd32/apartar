<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use DomainException;

final class AccessVerificationFailedException extends DomainException
{
    public const MESSAGE = 'No encontramos un apartado con esa cédula y ese código. Revisa los dos datos: el código está en tu confirmación y empieza por AP-.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGE);
    }
}
