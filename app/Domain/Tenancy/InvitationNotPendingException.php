<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class InvitationNotPendingException extends RuntimeException
{
    public const STATUS = 422;

    public static function for(string $name): self
    {
        return new self(sprintf(
            '%s ya aceptó su invitación y forma parte del equipo. Para retirarle el acceso, '
            .'desactívalo en lugar de revocar la invitación.',
            $name,
        ));
    }
}
