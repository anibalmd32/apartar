<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * El enlace del negocio ya no se puede cambiar (`REQ-ORG-006` SP-5,
 * `REQ-ORG-001` RB-2).
 *
 * El slug es editable **mientras no haya catálogos publicados**, y ahí se
 * cierra. No es una restricción técnica: es que la vitrina `/{org-slug}` se
 * comparte por WhatsApp y se pega en una bio de Instagram, y cambiarla rompe
 * todos los enlaces que ya están circulando. El negocio no vería un error;
 * vería que dejan de llegarle clientes, sin nada que lo explique.
 *
 * Es distinto de `SlugTakenException`, y por eso no se reutiliza: aquel dice
 * «ese nombre es de otro» y tiene salida —una variante libre—; este dice «el
 * tuyo ya está en la calle» y no la tiene.
 *
 * `422`: la petición está bien formada y quien la hace tiene permiso de sobra.
 * Lo que no se puede es aceptarla.
 */
final class SlugLockedException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-5).
     */
    public const STATUS = 422;

    public static function hasPublishedCatalogs(string $slug): self
    {
        return new self(sprintf(
            'El enlace de tu negocio (%s) ya no se puede cambiar: tienes catálogos publicados y '
            .'cambiarlo rompería los enlaces que ya compartiste con tus clientes.',
            $slug,
        ));
    }
}
