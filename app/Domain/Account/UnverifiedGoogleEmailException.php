<?php

declare(strict_types=1);

namespace App\Domain\Account;

use DomainException;

final class UnverifiedGoogleEmailException extends DomainException
{
    public const MESSAGE = 'Tu cuenta de Google no tiene el correo verificado, así que no podemos usarla. Puedes seguir viendo el catálogo y apartar sin cuenta.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGE);
    }
}
