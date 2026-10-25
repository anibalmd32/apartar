<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\TenantSurface;
use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TenantResolverTest extends TestCase
{
    use ReusesMigratedDatabase;

    private TenantResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new TenantResolver;
    }

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

    public function test_the_organizations_of_several_catalogs_resolve_by_catalog_id(): void
    {
        $organization = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $ours = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->create());
        $theirs = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->create());

        $this->withoutOrganization();

        $owners = $this->resolver->organizationsOfCatalogs([(int) $ours->getKey(), (int) $theirs->getKey(), 999_999]);

        $this->assertEqualsCanonicalizing([(int) $ours->getKey(), (int) $theirs->getKey()], array_keys($owners));
        $this->assertTrue($organization->is($owners[(int) $ours->getKey()]));
        $this->assertTrue($neighbour->is($owners[(int) $theirs->getKey()]));
        $this->assertSame([], $this->resolver->organizationsOfCatalogs([]));
    }

    public function test_an_unknown_catalog_slug_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->resolver->resolve(TenantSurface::PublicCatalog, 'no-existe');
    }

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

    public function test_the_platform_resolves_to_no_organization(): void
    {
        $this->assertNull($this->resolver->resolve(TenantSurface::Platform));
    }

    public function test_a_job_resolves_from_the_organization_id_in_its_payload(): void
    {
        $organization = Organization::factory()->create();

        $resolved = $this->resolver->forOrganizationId((int) $organization->getKey());

        $this->assertTrue($organization->is($resolved));
    }

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

    public function test_resolving_does_not_set_the_tenant(): void
    {
        $organization = Organization::factory()->create(['storefront_enabled' => true]);

        $this->resolver->resolve(TenantSurface::Storefront, $organization->slug);

        $this->assertFalse(app(\App\Domain\Tenancy\TenantContext::class)->hasOrganization());
    }
}
