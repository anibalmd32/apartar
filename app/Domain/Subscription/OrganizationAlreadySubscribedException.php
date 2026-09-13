<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

/**
 * Se intentó dar una segunda suscripción a una organización
 * (`REQ-SUB-002` RB-1 y SP-2).
 *
 * La base lo impide con `UNIQUE(organization_id)`, y esta excepción existe
 * para que lo que llegue arriba sea una regla del dominio y no un
 * `QueryException` con el nombre del índice dentro. La diferencia importa en
 * los dos sentidos: quien llama puede distinguir este caso de un fallo de base
 * de datos, y quien lee el error no tiene que saber cómo se llama la
 * restricción para entender qué pasó.
 *
 * La `UNIQUE` no sobra por tener esta comprobación: dos registros simultáneos
 * pasarían los dos por el `exists()` y solo uno por el índice.
 */
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
