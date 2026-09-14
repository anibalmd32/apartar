<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * El usuario del equipo tiene el acceso desactivado (`REQ-BOF-001` RB-4,
 * `REQ-ORG-005` RB-1).
 *
 * La baja de un miembro es **lógica**: la fila se conserva para que
 * `reviewed_by`, `registered_by` y la auditoría de la cobranza sigan
 * señalando a alguien. Lo que se le retira es la sesión, y se le retira en la
 * **siguiente petición**, no en el próximo inicio de sesión: una revocación
 * que espera a que a la persona le apetezca volver a entrar no es una
 * revocación.
 *
 * El mensaje es el mismo en los dos caminos que llevan aquí —intentar entrar y
 * seguir navegando con la sesión abierta— y por eso vive en un solo sitio.
 * También es deliberadamente escueto: no dice quién lo desactivó ni cuándo,
 * porque eso es una conversación con su `owner`, no un dato que deba leer
 * cualquiera que teclee un correo ajeno.
 */
final class AdminDisabledException extends RuntimeException
{
    /**
     * Lo que ve la persona, entre y no entre.
     */
    public const MESSAGE = 'Tu acceso a este negocio está desactivado. Habla con el dueño de la organización.';

    public static function forEmail(string $email): self
    {
        return new self(sprintf('%s (%s)', self::MESSAGE, $email));
    }
}
