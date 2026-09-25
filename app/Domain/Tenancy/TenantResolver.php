<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

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
     * sesión, el **slug** del catálogo de la URL, la ficha del portal, o el
     * slug de la vitrina. La plataforma no pasa ninguno porque opera sin
     * tenant.
     *
     * El catálogo llega como slug y no como modelo, al revés que el usuario o
     * la ficha, porque es el único de los tres que **no se puede resolver
     * antes que el tenant**: `catalogs` lleva el global scope, y buscarlo sin
     * organización fijada lanza. Ver `forCatalogSlug()`.
     *
     * @param  object|string|null  $identifier  fuente declarada por la superficie
     *
     * @throws ModelNotFoundException<Organization> si la superficie exige tenant y no se resuelve
     */
    public function resolve(TenantSurface $surface, object|string|null $identifier = null): ?Organization
    {
        return match ($surface) {
            TenantSurface::Backoffice => $this->forTenantRecord($identifier, 'la sesión del backoffice'),
            TenantSurface::PublicCatalog => $this->forCatalogSlug(is_string($identifier) ? $identifier : ''),
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
     * Organización de la vitrina `/{org-slug}`, **solo si la tiene activada**
     * (`REQ-ORG-006` RB-6).
     *
     * La vitrina apagada y el slug que no es de nadie son **la misma
     * consulta sin fila**, y por tanto la misma excepción con los mismos datos
     * y el mismo `404` (`T-036`). Se decide aquí, antes de fijar el tenant, y
     * no en el controlador: una vez fijado, la respuesta de error ya llevaría
     * cosas del negocio —su zona horaria, su tasa en las props compartidas— y
     * dejaría de ser idéntica. Si la vitrina apagada se distinguiera en algo,
     * bastaría probar slugs en la barra de direcciones para saber qué negocios
     * existen en la plataforma.
     *
     * @throws ModelNotFoundException<Organization>
     */
    public function forOrganizationSlug(string $slug): Organization
    {
        $organization = Organization::query()->where('slug', $slug)->where('storefront_enabled', true)->first();

        if (! $organization instanceof Organization) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$slug]);
        }

        return $organization;
    }

    /**
     * El catálogo público `/c/{slug}`, con su organización ya puesta
     * (`REQ-CAT-021` RB-3).
     *
     * ## La fila que da el tenant es la fila del binding
     *
     * El catálogo es la fuente del tenant, pero es también una tabla **del**
     * tenant: `Catalog::query()` sin organización fijada lanza, y es correcto
     * que lance. La salida no es `withoutTenantScope()` —cerrado a la lista de
     * `REQ-QAX-008` RB-4b, y aquí no pinta nada— sino una lectura por la
     * clave que es única en toda la plataforma precisamente para esto:
     * `catalogs.slug` es una de las tres excepciones de
     * `GlobalUniqueExceptions`, las «claves que hay que poder resolver antes de
     * saber en qué organización estamos».
     *
     * Hasta `T-038` esa lectura se quedaba solo con `organization_id` y el
     * *binding* volvía a pedir la misma fila con el global scope puesto: dos
     * consultas para una fila, y otra más para la organización cuando la
     * página preguntaba `$catalog->organization`. La fila es **la misma** —el
     * slug es único y la organización que se fija es la suya—, así que se lee
     * una vez, se hidrata y se entrega con su relación ya cargada; el
     * middleware la pone en el parámetro de la ruta y el *binding* la respeta.
     * Lo que no cambia es el orden: el modelo **no sale de aquí** a nadie más
     * que al middleware que fija su propio tenant antes de dejar pasar la
     * petición.
     *
     * No es el `DB::table()` que filtra el tenant a mano y que `tenancy:audit`
     * existe para que nadie escriba: no se filtra por ninguna organización, se
     * **descubre** cuál es.
     *
     * Un slug que no existe da el mismo `404` que cualquier binding fallido
     * (`REQ-CAT-021` SP-1): no dice nada que la URL no dijera ya.
     *
     * @throws ModelNotFoundException<Organization>
     */
    public function publicCatalog(string $slug): Catalog
    {
        $row = $slug === '' ? null : DB::table('catalogs')->where('slug', $slug)->first();
        $organizationId = $row === null ? null : ($row->{TenantScope::COLUMN} ?? null);

        if (! is_numeric($organizationId)) {
            throw (new ModelNotFoundException)->setModel(Organization::class, ['el catálogo público']);
        }

        $catalog = (new Catalog)->newFromBuilder((array) $row);
        $catalog->setRelation('organization', $this->forOrganizationId((int) $organizationId));

        return $catalog;
    }

    /**
     * @throws ModelNotFoundException<Organization>
     */
    private function forCatalogSlug(string $slug): Organization
    {
        return $this->publicCatalog($slug)->organization;
    }

    /**
     * Organización de cualquier fila del tenant: el usuario de la sesión o la
     * ficha del portal.
     *
     * Las dos superficies llegan aquí porque las dos tienen ya resuelto su
     * modelo —lo resuelve la sesión— y lo único que falta es leer su
     * `organization_id`. Recibe `object` y no un tipo concreto para no
     * depender de `User` ni de `Customer`, que llega en H3 y no cambia esta
     * lógica.
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
