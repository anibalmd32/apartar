<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Subscription;
use App\Models\User;

/**
 * Quién puede tocar la suscripción y su facturación (`REQ-ORG-003` RB-1,
 * áreas `Subscription` y `Billing`).
 *
 * `T-009` la escribió con los métodos recibiendo solo al usuario y la registró
 * como *gates* con nombre, porque `Subscription` no existía y el
 * descubrimiento por convención necesita el modelo. Ya existe (`T-011`), así
 * que Laravel la encuentra sola desde `App\Models\Subscription` y los métodos
 * reciben la fila.
 *
 * Recibirla no es cosmético: ahora cada método comprueba **también** que la
 * suscripción sea la de la organización de quien actúa. Mientras la
 * suscripción llegaba por la URL, el aislamiento ya lo impedía —un
 * identificador ajeno da `404` (`REQ-ORG-002` RB-6)—; en cuanto una `Action`
 * la resuelva por su cuenta, esta es la única comprobación que queda.
 *
 * Lo que sigue sin poder decidirse aquí es todo lo que depende del **estado**
 * de la suscripción —que una organización suspendida conserve la lectura de la
 * cobranza (`REQ-ORG-008` RB-3), o que un cambio de plan respete los límites
 * en uso (`REQ-SUB-008`)—. Eso no es rol, es estado, y su sitio es
 * `SubscriptionStatus` con `LimitEnforcer`, que llegan con H5.
 */
final class SubscriptionPolicy
{
    /**
     * Ver el plan contratado y el consumo (`REQ-SUB-001`).
     */
    public function view(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Subscription);
    }

    /**
     * Cambiar de plan (`REQ-SUB-005`).
     */
    public function changePlan(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Subscription);
    }

    /**
     * Ver las facturas de la suscripción (`REQ-SUB-006`).
     */
    public function viewInvoices(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Billing);
    }

    /**
     * Subir el comprobante de pago de una factura de la suscripción.
     *
     * Es el comprobante que el negocio le paga a la plataforma, no el que sus
     * clientes le suben a él: ese lo concilia también el `member`
     * (`OrganizationCapability::Receipts`).
     */
    public function uploadPaymentProof(User $user, Subscription $subscription): bool
    {
        return $this->governs($user, $subscription, OrganizationCapability::Billing);
    }

    private function governs(User $user, Subscription $subscription, OrganizationCapability $capability): bool
    {
        return $user->organization_id === $subscription->organization_id
            && $user->role->can($capability);
    }
}
