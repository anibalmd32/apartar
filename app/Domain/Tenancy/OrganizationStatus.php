<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Estado de una organización dentro de la plataforma (`REQ-ORG-008`).
 *
 * Invariante del estado `Suspended`, y es la regla que no se negocia (`Q7`):
 * suspender corta el acceso del **equipo** al backoffice, y **nunca**
 * interrumpe un apartado en curso, el portal del cliente final ni la subida de
 * comprobantes. Si un negocio deja de pagar su suscripción, el perjudicado no
 * puede ser el cliente que le debe dinero a ese negocio.
 *
 * La matriz de capacidades (`allowsTeamAccess()`, `allowsCustomerAccess()`) es
 * de `T-009`: aquí solo están los casos, que es lo que necesita el cast del
 * modelo.
 */
enum OrganizationStatus: string
{
    /** Operación normal. */
    case Active = 'active';

    /** Impago prolongado: el equipo pierde el backoffice, el cliente no. */
    case Suspended = 'suspended';

    /** Baja solicitada por el `owner`; los datos se conservan. */
    case Closed = 'closed';
}
