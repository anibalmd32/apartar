<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use RuntimeException;

/**
 * Se pidió un ajuste que no existe (`REQ-ORG-006` RB-2).
 *
 * Es **la mitad ruidosa de un par**, y lo interesante es la otra. RB-2 dice
 * que una clave desconocida **se ignora en silencio**, y eso es correcto para
 * lo que llega de fuera: permite desplegar una versión que ya escribe una
 * clave nueva contra filas sembradas antes, y leer filas de una clave que una
 * versión anterior escribió y esta ya retiró —`storefront_enabled` es
 * exactamente ese caso—. Ahí el silencio es la funcionalidad.
 *
 * Pero cuando es **nuestro propio código** el que nombra una clave por su
 * cadena, un valor desconocido no es un despliegue a medias: es una errata que
 * haría leer un default en lugar del ajuste del negocio, y sin ruido nadie lo
 * notaría hasta ver un vencimiento calculado con tres días de gracia que ese
 * negocio nunca configuró.
 *
 * De ahí las dos puertas de `SettingKey`: `tryFromKey()` para lo que llega de
 * fuera y `fromKey()` para lo que escribimos nosotros.
 */
final class UnknownSettingException extends RuntimeException
{
    public static function named(string $key): self
    {
        $known = implode(', ', array_column(SettingKey::cases(), 'value'));

        return new self(sprintf(
            'No existe el ajuste «%s». Las claves de organization_settings son: %s.',
            $key,
            $known,
        ));
    }
}
