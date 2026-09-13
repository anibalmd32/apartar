<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\User;

/**
 * Quién puede tocar la suscripción y su facturación (`REQ-ORG-003` RB-1,
 * áreas `Subscription` y `Billing`).
 *
 * **`Subscription` no existe todavía**: llega en `T-011` con los planes. Lo
 * que sí existe desde hoy es la regla —solo el `owner`—, y esa es la mitad que
 * se puede escribir y probar sin el modelo: los métodos reciben únicamente al
 * usuario, así que ni dependen de la fila ni fingen leerla.
 *
 * Por eso sus habilidades se registran como *gates* con nombre en
 * `AppServiceProvider` en vez de por descubrimiento automático, que necesita
 * la clase del modelo. Cuando `T-011` traiga `Subscription`, esta policy pasa
 * a descubrirse sola por convención y los métodos que necesiten la fila
 * —comprobar el plan vigente antes de un cambio, por ejemplo— podrán recibirla
 * sin que cambie lo que ya está escrito aquí.
 *
 * Lo que **no** se puede afirmar hoy y queda para `T-011`: nada de lo que
 * depende del estado de la suscripción —que una organización suspendida por
 * impago conserve el acceso de solo lectura a la cobranza (`REQ-ORG-008`
 * RB-3), o que un cambio de plan respete los límites en uso
 * (`REQ-SUB-008`)—. Eso no es rol, es estado, y sin `Subscription` no hay
 * estado que leer.
 */
final class SubscriptionPolicy
{
    /**
     * Ver el plan contratado y el consumo (`REQ-SUB-001`).
     */
    public function view(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Subscription);
    }

    /**
     * Cambiar de plan (`REQ-SUB-008`).
     */
    public function changePlan(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Subscription);
    }

    /**
     * Ver las facturas de la suscripción (`REQ-SUB-005`).
     */
    public function viewInvoices(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Billing);
    }

    /**
     * Subir el comprobante de pago de una factura de la suscripción.
     *
     * Es el comprobante que el negocio le paga a la plataforma, no el que sus
     * clientes le suben a él: ese lo concilia también el `member`
     * (`OrganizationCapability::Receipts`).
     */
    public function uploadPaymentProof(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Billing);
    }
}
