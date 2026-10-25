<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class LastOwnerException extends RuntimeException
{
    public const STATUS = 422;

    public static function wouldLeaveNone(string $subject, string $operation): self
    {
        return new self(sprintf(
            'No se puede %s a %s: es el último owner activo de la organización y toda '
            .'organización tiene que conservar al menos uno. Asciende antes a otra persona.',
            $operation,
            $subject,
        ));
    }
}
