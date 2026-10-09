<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Customer;
use App\Models\User;

/**
 * Quién ve y gestiona las fichas de clientes de un negocio (`REQ-BOF-040` RB-9,
 * `REQ-BOF-041`, `REQ-IDN-006` RB-1).
 *
 * Los dos roles: atender a un cliente que escribe es operación diaria
 * (`OrganizationCapability::Customers`). La ficha de otro negocio no llega
 * aquí —el binding va filtrado por el tenant y da `404`—; la comparación de
 * organización se repite para una llamada que no venga de una ruta.
 */
final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->disabled_at === null && $user->role->can(OrganizationCapability::Customers);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user) && $user->organization_id === (int) $customer->organization_id;
    }

    /**
     * Fusionar dos fichas (`REQ-IDN-006` RB-1): un usuario de **esa**
     * organización que gestione clientes, y las dos fichas suyas.
     */
    public function merge(User $user, Customer $survivor, Customer $absorbed): bool
    {
        return $this->view($user, $survivor) && $user->organization_id === (int) $absorbed->organization_id;
    }
}
