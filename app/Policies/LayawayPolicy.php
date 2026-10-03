<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Layaway;
use App\Models\User;

/**
 * Quién gestiona los apartados de un negocio desde el backoffice
 * (`REQ-LAY-009` RB-1, `REQ-LAY-010`, `REQ-LAY-011`).
 *
 * Los dos roles: la cobranza es operación diaria (`OrganizationCapability::
 * Collections`), y un empleado que cobra y no puede cancelar el apartado que
 * el cliente abandonó tendría que pedírselo al dueño cada vez.
 *
 * El apartado de otro negocio ni siquiera llega aquí: el binding de la ruta ya
 * va filtrado por el tenant y responde `404` (`REQ-ORG-002` RB-6). La
 * comparación de organización se repite igual, para que una llamada que no
 * venga de una ruta —un comando, un job— tampoco cruce.
 */
final class LayawayPolicy
{
    public function manage(User $user, Layaway $layaway): bool
    {
        return $user->organization_id === (int) $layaway->organization_id
            && $user->role->can(OrganizationCapability::Collections);
    }
}
