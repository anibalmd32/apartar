<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\Organization;
use App\Models\User;

/**
 * Quién puede gobernar la organización (`REQ-ORG-003` RB-1 y RB-4).
 *
 * Tres de las seis áreas de gobierno viven aquí —ajustes, cuentas de cobro y
 * branding—; el equipo está en `UserPolicy` y la suscripción con su
 * facturación en `SubscriptionPolicy`.
 *
 * Cada método hace **dos** comprobaciones y las dos hacen falta:
 *
 *  1. que quien actúa pertenezca a esa organización, porque una policy que
 *     solo mirara el rol autorizaría al `owner` de un negocio sobre los
 *     ajustes de otro;
 *  2. que su rol ejerza la capacidad, que es el reparto de RB-1.
 *
 * La primera parece redundante con el aislamiento —el binding de ruta ya
 * devuelve `404` para un identificador ajeno (`REQ-ORG-002` RB-6)— y lo es
 * mientras el modelo llegue por la URL. Deja de serlo en cuanto una `Action`
 * autoriza sobre un modelo que resolvió ella misma, que es la mitad de los
 * casos.
 */
final class OrganizationPolicy
{
    /**
     * Ver la pantalla de ajustes (`REQ-ORG-006`).
     */
    public function viewSettings(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Settings);
    }

    /**
     * Cambiar los ajustes de operación: tasa, plazos, frecuencia de cuotas.
     */
    public function updateSettings(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Settings);
    }

    /**
     * Gestionar las cuentas donde el negocio recibe el dinero
     * (`REQ-ORG-007`).
     *
     * Es la capacidad más sensible del `owner` y por eso no está con la
     * cobranza: un empleado registra pagos y aprueba comprobantes, pero no
     * decide a qué cuenta va el dinero.
     */
    public function managePaymentAccounts(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::PaymentAccounts);
    }

    /**
     * Cambiar la identidad de la vitrina: nombre, slug, logo y color
     * (`REQ-ORG-006`).
     */
    public function manageBranding(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Branding);
    }

    /**
     * Leer el registro de quién hizo qué (`REQ-BOF-005` RB-9, SP-4).
     *
     * Del `owner` y de nadie más, y por un motivo que no es de jerarquía: la
     * auditoría contiene **lo que hizo cada miembro del equipo**. Dársela a
     * quien aparece en ella convertiría una herramienta de control en una de
     * vigilancia mutua, y quien responde ante un cliente por lo que hizo su
     * empleado es el dueño.
     */
    public function viewAuditLog(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Audit);
    }

    /**
     * Operar el día a día del negocio: lo que los dos roles hacen.
     *
     * Existe para que las pantallas de operación tengan dónde autorizar la
     * pertenencia sin volver a decidir el reparto de roles por su cuenta.
     */
    public function operate(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization);
    }

    /**
     * Mantener el inventario: productos y la taxonomía con la que se ordenan
     * (`REQ-CAT-001`, `REQ-BOF-023`).
     *
     * De los dos roles, y esa es la respuesta correcta aunque la taxonomía
     * parezca configuración: categorías y etiquetas son **cómo se ordena el
     * inventario**, no cómo se gobierna el negocio. Un empleado que carga
     * productos y no puede crear la categoría donde ponerlos tendría que
     * pedírsela al dueño cada vez, que es justo lo que `OrganizationCapability`
     * describe como encoger la mitad del `member` hasta dejar el rol inútil.
     *
     * Pasa por `governs()` y no por `operate()` para que el área quede
     * **nombrada**: el menú se filtra por `products` y la frontera de
     * `REQ-ORG-003` RB-1 sigue declarada en un solo sitio. El día que esa
     * capacidad cambiara de lado, esta policy lo sabría sola.
     */
    public function manageProducts(User $user, Organization $organization): bool
    {
        return $this->governs($user, $organization, OrganizationCapability::Products);
    }

    private function governs(User $user, Organization $organization, OrganizationCapability $capability): bool
    {
        return $this->belongsTo($user, $organization) && $user->role->can($capability);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organization_id === (int) $organization->getKey();
    }
}
