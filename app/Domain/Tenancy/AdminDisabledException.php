<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class AdminDisabledException extends RuntimeException
{
    public const MESSAGE = 'Tu acceso a este negocio está desactivado. Habla con el dueño de la organización.';

    public static function forEmail(string $email): self
    {
        return new self(sprintf('%s (%s)', self::MESSAGE, $email));
    }
}
