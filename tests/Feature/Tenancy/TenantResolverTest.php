<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\TenantSurface;
use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `TenantResolver` (`REQ-ORG-002` RB-4 y RB-6).
 *
 * Las cuatro vías de la tabla de RB-4, más la del payload de un job. El
 * resolutor **no fija** el tenant: eso es del middleware de cada superficie
 * (`T-006`), y separarlo es lo que permite resolver sin efectos secundarios.
 */
final class TenantResolverTest extends TestCase
{
    use RefreshDatabase;

    private TenantResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new TenantResolver;
    }

    /**
     * Cualquier fila del tenant sirve de fuente: la sesión trae el usuario, la
     * URL del catálogo trae el catálogo y el portal trae la ficha. Los tres
     * llegan ya resueltos y lo único que falta es leer su `organization_id`.
     */
    private function tenantRecordOf(Organization $organization): object
    {
        return new class((int) $organization->getKey())
        {
            public function __construct(public int $organization_id) {}
        };
    }

    public function test_the_backoffice_resolves_from_the_session_user(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $resolved = $this->resolver->resolve(
            TenantSurface::Backoffice,
            $this->tenantRecordOf($organization),
        );

        $this->assertTrue($organization->is($resolved));
    }

    /**
     * El catálogo llega como **slug**: es el único de los identificadores que
     * no se puede resolver antes que el tenant, porque `catalogs` lleva el
     * global scope. Con dos catálogos en dos organizaciones, cada slug da la
     * suya, y sin ninguna fijada de antemano.
     */
    public function test_the_public_catalog_resolves_from_the_catalog_slug(): void
    {
        $organization = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $ours = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->create());
        $theirs = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->create());

        $this->withoutOrganization();

        $this->assertTrue($organization->is($this->resolver->resolve(TenantSurface::PublicCatalog, $ours->slug)));
        $this->assertTrue($neighbour->is($this->resolver->resolve(TenantSurface::PublicCatalog, $theirs->slug)));
    }

    /**
     * SP-1 de `REQ-CAT-021`: un slug que no existe es el `404` de cualquier
     * binding fallido.
     */
    public function test_an_unknown_catalog_slug_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->resolver->resolve(TenantSurface::PublicCatalog, 'no-existe');
    }

    /**
     * Y una fila suelta ya no vale como fuente de la superficie pública: lo
     * que viaja es el slug de la URL.
     */
    public function test_the_public_catalog_does_not_accept_a_record(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->resolver->resolve(
            TenantSurface::PublicCatalog,
            $this->tenantRecordOf(Organization::factory()->create()),
        );
    }

    public function test_the_portal_resolves_from_the_customer_record(): void
    {
        $organization = Organization::factory()->create();

        $resolved = $this->resolver->resolve(
            TenantSurface::Portal,
            $this->tenantRecordOf($organization),
        );

        $this->assertTrue($organization->is($resolved));
    }

    public function test_the_storefront_resolves_from_the_organization_slug(): void
    {
        $organization = Organization::factory()->create(['storefront_enabled' => true]);
        $this->neighbourOrganization();

        $resolved = $this->resolver->resolve(TenantSurface::Storefront, $organization->slug);

        $this->assertTrue($organization->is($resolved));
    }

    /**
     * RB-4: la plataforma opera **sin** tenant, y por eso consulta con
     * `withoutTenantScope()`.
     */
    public function test_the_platform_resolves_to_no_organization(): void
    {
        $this->assertNull($this->resolver->resolve(TenantSurface::Platform));
    }

    /**
     * RB-5: un job no hereda el tenant, lo recibe en su payload y lo vuelve a
     * resolver.
     */
    public function test_a_job_resolves_from_the_organization_id_in_its_payload(): void
    {
        $organization = Organization::factory()->create();

        $resolved = $this->resolver->forOrganizationId((int) $organization->getKey());

        $this->assertTrue($organization->is($resolved));
    }

    /**
     * RB-6: un identificador ajeno o inexistente produce `404`, nunca `403`.
     * No se revela la existencia del recurso ajeno.
     */
    public function test_an_unknown_organization_id_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->resolver->forOrganizationId(9_999);
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        Organization::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->resolver->forOrganizationSlug('un-negocio-que-no-existe');
    }

    /**
     * `T-036` · la vitrina apagada no es una vitrina: la misma consulta sin
     * fila, y la misma excepción, que un slug que no existe.
     */
    public function test_a_storefront_that_is_switched_off_is_not_found_like_an_unknown_slug(): void
    {
        $organization = Organization::factory()->create(['storefront_enabled' => false]);

        $this->expectException(ModelNotFoundException::class);

        $this->resolver->resolve(TenantSurface::Storefront, $organization->slug);
    }

    public function test_a_surface_without_its_source_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->resolver->resolve(TenantSurface::Backoffice, null);
    }

    public function test_the_storefront_without_a_slug_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->resolver->resolve(TenantSurface::Storefront, null);
    }

    /**
     * Resolver no es fijar: tras resolver, el contexto sigue vacío. Lo
     * contrario convertiría cualquier comprobación previa a un `404` en un
     * cambio de estado.
     */
    public function test_resolving_does_not_set_the_tenant(): void
    {
        $organization = Organization::factory()->create(['storefront_enabled' => true]);

        $this->resolver->resolve(TenantSurface::Storefront, $organization->slug);

        $this->assertFalse(app(\App\Domain\Tenancy\TenantContext::class)->hasOrganization());
    }
}
