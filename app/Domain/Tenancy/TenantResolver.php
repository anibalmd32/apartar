<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Traduce la superficie de la petición a la organización que le corresponde
 * (`REQ-ORG-002` RB-4).
 *
 * Resuelve, **no fija**: quien llama a `TenantContext::setOrganization()` es
 * el middleware de cada superficie (`T-006`) o el propio job. Separarlo es lo
 * que permite resolver sin efectos secundarios —por ejemplo, para decidir un
 * `404` antes de tocar nada.
 *
 * Cuando no hay organización se lanza `ModelNotFoundException` y no una
 * excepción propia, porque es exactamente el `404` que pide RB-6: un
 * identificador de otra organización no revela que el recurso existe.
 */
final class TenantResolver
{
    /**
     * Resuelve la organización de una superficie.
     *
     * `$identifier` es lo que esa superficie tiene a mano: el usuario de la
     * sesión, el catálogo de la URL, la ficha del portal, o el slug de la
     * vitrina. La plataforma no pasa ninguno porque opera sin tenant.
     *
     * @param  object|string|null  $identifier  fuente declarada por la superficie
     *
     * @throws ModelNotFoundException<Organization> si la superficie exige tenant y no se resuelve
     */
    public function resolve(TenantSurface $surface, object|string|null $identifier = null): ?Organization
    {
        return match ($surface) {
            TenantSurface::Backoffice => $this->forTenantRecord($identifier, 'la sesión del backoffice'),
            TenantSurface::PublicCatalog => $this->forTenantRecord($identifier, 'el catálogo público'),
            TenantSurface::Portal => $this->forTenantRecord($identifier, 'la ficha del portal'),
            TenantSurface::Storefront => $this->forOrganizationSlug(is_string($identifier) ? $identifier : ''),
            TenantSurface::Platform => null,
        };
    }

    /**
     * Organización de un identificador que viaja en el payload de un job.
     *
     * Un job **nunca** hereda el tenant del proceso que lo encoló (RB-5): lo
     * recibe como parámetro y lo vuelve a resolver aquí.
     *
     * @throws ModelNotFoundException<Organization>
     */
    public function forOrganizationId(int $organizationId): Organization
    {
        $organization = Organization::query()->find($organizationId);

        if (! $organization instanceof Organization) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$organizationId]);
        }

        return $organization;
    }

    /**
     * Organización de la vitrina `/{org-slug}`.
     *
     * @throws ModelNotFoundException<Organization>
     */
    public function forOrganizationSlug(string $slug): Organization
    {
        $organization = Organization::query()->where('slug', $slug)->first();

        if (! $organization instanceof Organization) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$slug]);
        }

        return $organization;
    }

    /**
     * Organización de cualquier fila del tenant: el usuario de la sesión, el
     * catálogo de la URL o la ficha del portal.
     *
     * Las tres superficies llegan aquí porque las tres tienen ya resuelto su
     * modelo —lo resolvió el *route binding* o la sesión— y lo único que falta
     * es leer su `organization_id`. Recibe `object` y no un tipo concreto para
     * no depender de `User`, `Catalog` ni `Customer`, que llegan en tareas
     * posteriores y no cambian esta lógica.
     *
     * @param  string  $source  descripción de la superficie, para el mensaje de error
     *
     * @throws ModelNotFoundException<Organization>
     */
    private function forTenantRecord(?object $record, string $source): Organization
    {
        $organizationId = $record === null
            ? null
            : ($record->{TenantScope::COLUMN} ?? null);

        if (! is_numeric($organizationId)) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$source]);
        }

        return $this->forOrganizationId((int) $organizationId);
    }
}
