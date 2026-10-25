<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

final class OrganizationAlreadySubscribedException extends RuntimeException
{
    public static function for(int $organizationId): self
    {
        return new self(sprintf(
            'La organización %d ya tiene suscripción, y toda organización tiene exactamente una. '
            .'Para moverla de plan se cambia la que tiene, no se crea otra.',
            $organizationId,
        ));
    }
}
