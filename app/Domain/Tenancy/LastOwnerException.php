<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * La operación habría dejado a la organización sin ningún `owner` activo.
 *
 * `REQ-ORG-003` RB-2 y SP-2, `REQ-ORG-005` RB-3 y SP-2. No es una restricción
 * de permisos —quien lo intenta suele ser precisamente el `owner`, que puede
 * hacerlo todo— sino un estado del que no se sale: sin `owner` no queda nadie
 * capaz de invitar, de ascender a otro ni de tocar la suscripción, y la
 * organización necesita una intervención manual en la base de datos para
 * volver a ser operable.
 *
 * Se traduce a `422` en `bootstrap/app.php`: el intento es legítimo y está
 * bien formado, lo que no se puede es aceptarlo.
 */
final class LastOwnerException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (`REQ-ORG-003` SP-2).
     */
    public const STATUS = 422;

    /**
     * No queda otro `owner` activo que pueda quedarse al mando.
     *
     * @param  string  $subject  nombre de la persona sobre la que se operaba
     * @param  string  $operation  lo que se intentaba hacer, para el mensaje
     */
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
