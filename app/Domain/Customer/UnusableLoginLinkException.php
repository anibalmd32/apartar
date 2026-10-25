<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use DomainException;

final class UnusableLoginLinkException extends DomainException
{
    public const MESSAGE = 'Este enlace ya no sirve: caducó, ya se usó o no es correcto. Pide otro, o entra con tu cédula y el código de tu apartado.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGE);
    }
}
