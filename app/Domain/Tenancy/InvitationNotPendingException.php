<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * Se intentó reenviar o revocar la invitación de alguien que ya la aceptó
 * (`REQ-ORG-004` RB-4 y RB-6).
 *
 * Las dos operaciones son del **ciclo de vida de la invitación**, y una
 * invitación aceptada dejó de existir: la persona es ya un miembro del equipo
 * con su contraseña puesta. Revocarla ahí significaría borrar a un compañero,
 * que es justo lo que `REQ-ORG-005` RB-1 prohíbe —para eso está la baja
 * lógica—, y reenviarla, emitirle un enlace para fijar una contraseña que ya
 * tiene.
 *
 * `422`: la petición está bien formada, pero llega tarde. Normalmente porque
 * dos pestañas de la pantalla de Equipo muestran estados distintos.
 */
final class InvitationNotPendingException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde.
     */
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
