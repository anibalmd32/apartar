<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * Nadie se desactiva a sí mismo (`REQ-ORG-005` RB-4 y SP-1).
 *
 * No es una regla de permisos —el `owner` puede desactivar a cualquiera de su
 * equipo, y a sí mismo es a quien más podría— sino una salvaguarda contra el
 * clic equivocado: quien se desactiva pierde la sesión en su siguiente
 * petición (RB-2) y ya no puede volver a activarse, así que un error de un
 * segundo se convierte en una llamada a soporte. Si de verdad quiere salir del
 * equipo, otro `owner` lo desactiva.
 *
 * `422` y no `403` por lo mismo que `LastOwnerException`: la petición está
 * bien formada y quien la hace tiene permiso; lo que no se puede es aceptarla.
 */
final class CannotDisableSelfException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-1).
     */
    public const STATUS = 422;

    public const MESSAGE = 'No puedes desactivar tu propia cuenta: perderías el acceso sin poder '
        .'recuperarlo tú mismo. Pídeselo a otro owner de la organización.';

    public static function make(): self
    {
        return new self(self::MESSAGE);
    }
}
