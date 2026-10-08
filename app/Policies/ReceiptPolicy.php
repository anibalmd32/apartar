<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Customer;
use App\Models\Receipt;
use App\Models\User;

/**
 * Quién puede ver un comprobante (`REQ-PAY-002` RB-2, SP-4).
 *
 * Dos actores, uno por superficie:
 *
 *  - **El cliente titular**, desde el portal: el comprobante es suyo si es de
 *    su ficha. Otro cliente del mismo negocio con una URL firmada válida
 *    recibe `403` (SP-4): la firma dice que alguien la generó, no para quién.
 *  - **El equipo del negocio**, desde el backoffice, con la capacidad
 *    `Receipts`. Hoy la tienen los dos roles (`REQ-ORG-003` RB-1: conciliar es
 *    operación diaria), así que el `false` por rol no tiene todavía a quién
 *    aplicarse; se pregunta igual para que el día que exista un rol sin ella
 *    no haya que acordarse de volver aquí.
 *
 * El comprobante de otro negocio ni siquiera llega aquí: el binding de las dos
 * rutas va filtrado por el tenant y responde `404` (`REQ-ORG-002` RB-6). La
 * organización se compara igual, como en `LayawayPolicy`, para que una
 * llamada que no venga de una ruta —un job, un comando— tampoco cruce.
 *
 * El `PlatformAdmin` (RB-11, SP-9) no tiene modelo todavía (`T-086`), así que
 * no puede pasar por aquí: la firma del método no lo admite.
 */
final class ReceiptPolicy
{
    public function view(User|Customer $actor, Receipt $receipt): bool
    {
        if ($actor->organization_id !== (int) $receipt->organization_id) {
            return false;
        }

        if ($actor instanceof Customer) {
            return $actor->id === (int) $receipt->customer_id;
        }

        return $actor->role->can(OrganizationCapability::Receipts);
    }

    /**
     * La bandeja de conciliación (`REQ-PAY-004`): solo el equipo, con la misma
     * capacidad que pide abrir cada archivo. Un cliente no tiene bandeja; la
     * suya es el historial del apartado, en el portal.
     */
    public function viewAny(User|Customer $actor): bool
    {
        return $actor instanceof User && $actor->role->can(OrganizationCapability::Receipts);
    }

    /**
     * Aprobar —y rechazar, con `T-062`— (`REQ-PAY-005` RB-2): un usuario
     * **de esa** organización con la capacidad de conciliar. Si sigue en
     * revisión lo decide la acción bajo el bloqueo, no esto: aquí se pregunta
     * quién, no cuándo.
     */
    public function review(User|Customer $actor, Receipt $receipt): bool
    {
        return $actor instanceof User
            && $actor->organization_id === (int) $receipt->organization_id
            && $actor->role->can(OrganizationCapability::Receipts);
    }
}
