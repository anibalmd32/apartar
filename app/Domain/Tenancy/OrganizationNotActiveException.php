<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * La organización no admite el acceso de su equipo al backoffice
 * (`REQ-BOF-001` RB-10, `REQ-ORG-008`).
 *
 * **Solo `closed` llega aquí.** Una organización `suspended` no lanza nada: su
 * equipo entra y opera en modo restringido (RB-9), y esa diferencia es la
 * regla del pivote que más va contra el instinto —un negocio que no ha pagado
 * su suscripción sigue teniendo clientes que le deben dinero, y cortarle el
 * acceso a cobrarles le impide conseguir con qué pagar—.
 *
 * El cierre sí es definitivo: lo pidió el propio `owner`, los datos se
 * conservan y no hay operación que hacer dentro (`REQ-ORG-008` RB-5). El
 * mensaje lo dice, porque quien lo lee suele ser un empleado que no se enteró.
 */
final class OrganizationNotActiveException extends RuntimeException
{
    /**
     * Lo que ve quien intenta entrar a un negocio cerrado.
     */
    public const CLOSED_MESSAGE = 'Este negocio cerró su cuenta en Apartar y su backoffice ya no está disponible.';

    public static function closed(string $organizationName): self
    {
        return new self(sprintf('%s (%s)', self::CLOSED_MESSAGE, $organizationName));
    }
}
