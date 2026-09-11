<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * `CreatesNeighbourOrganization` (`REQ-QAX-003` RB-10).
 *
 * La regla que este helper hace cumplir es la que convierte una fuga de tenant
 * en un fallo visible: con un solo tenant en la base, un `where` olvidado pasa
 * desapercibido porque **todo** lo que devuelve la consulta resulta ser, por
 * casualidad, del tenant correcto. Con dos, no.
 *
 * Está escrito como clase y no con la sintaxis de Pest a propósito: PHPStan no
 * resuelve el `$this` de las closures de Pest, y este archivo es justamente el
 * que ejercita métodos de instancia del caso base.
 */
final class NeighbourOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_real_neighbour_organization(): void
    {
        $this->assertSame(0, Organization::query()->count());

        $neighbour = $this->neighbourOrganization();

        $this->assertTrue($neighbour->exists);
        $this->assertSame(1, Organization::query()->count());
    }

    public function test_it_returns_the_same_neighbour_within_one_scenario(): void
    {
        $this->assertSame(
            $this->neighbourOrganization()->id,
            $this->neighbourOrganization()->id,
        );

        $this->assertSame(1, Organization::query()->count());
    }

    public function test_it_can_seed_additional_neighbours(): void
    {
        $this->neighbourOrganization();
        $extra = $this->createNeighbourOrganization();

        $this->assertSame(2, Organization::query()->count());
        $this->assertNotSame($this->neighbourOrganization()->id, $extra->id);
    }

    public function test_it_knows_whether_the_scenario_has_more_than_one_tenant(): void
    {
        $own = Organization::factory()->create();

        $this->assertFalse($this->hasNeighbourOrganization());

        $neighbour = $this->neighbourOrganization();

        $this->assertTrue($this->hasNeighbourOrganization());
        $this->assertNotSame($own->slug, $neighbour->slug);
    }

    /**
     * `organizations.slug` es `UNIQUE` global, así que la factory tiene que
     * poder sembrar varias organizaciones seguidas sin chocar.
     */
    public function test_the_factory_produces_unique_slugs(): void
    {
        $slugs = Organization::factory()->count(5)->create()->pluck('slug');

        $this->assertCount(5, $slugs->unique());
    }

    /**
     * El estado por defecto es el que necesita un escenario normal: activa y
     * sin marca de suspensión.
     */
    public function test_the_factory_produces_an_active_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->assertTrue($organization->status->value === 'active');
        $this->assertNull($organization->suspended_at);
        $this->assertFalse($organization->storefront_enabled);
        $this->assertSame('America/Caracas', $organization->timezone);
    }

    public function test_the_factory_can_produce_a_suspended_organization(): void
    {
        $organization = Organization::factory()->suspended()->create();

        $this->assertSame('suspended', $organization->status->value);
        $this->assertNotNull($organization->suspended_at);
    }

    /**
     * `REQ-ORG-008` RB-5: el cierre conserva los datos.
     */
    public function test_soft_deleting_keeps_the_row(): void
    {
        $organization = Organization::factory()->closed()->create();
        $organization->delete();

        $this->assertSoftDeleted($organization);
        $this->assertSame(1, Organization::withTrashed()->count());
    }

    /**
     * `T-005` completa el helper: además de sembrar la vecina, ya puede
     * situarse en una organización, que es lo que hace el middleware de
     * resolución en una petición real.
     */
    public function test_acting_as_an_organization_sets_the_tenant(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsOrganization($organization);

        $this->assertTrue(app(TenantContext::class)->organization()->is($organization));
    }

    public function test_acting_as_another_organization_replaces_the_previous_one(): void
    {
        $this->actingAsOrganization(Organization::factory()->create());

        $second = $this->neighbourOrganization();
        $this->actingAsOrganization($second);

        $this->assertSame((int) $second->getKey(), app(TenantContext::class)->organizationId());
    }

    public function test_within_an_organization_restores_the_previous_tenant(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($own);

        $seen = $this->withinOrganization(
            $neighbour,
            fn (): int => app(TenantContext::class)->organizationId(),
        );

        $this->assertSame((int) $neighbour->getKey(), $seen);
        $this->assertSame((int) $own->getKey(), app(TenantContext::class)->organizationId());
    }

    public function test_within_an_organization_restores_the_tenant_even_when_it_throws(): void
    {
        $own = Organization::factory()->create();
        $this->actingAsOrganization($own);

        try {
            $this->withinOrganization($this->neighbourOrganization(), function (): never {
                throw new RuntimeException('fallo dentro de la vecina');
            });
        } catch (RuntimeException) {
            // Lo que se comprueba es el estado que deja, no el fallo.
        }

        $this->assertSame((int) $own->getKey(), app(TenantContext::class)->organizationId());
    }

    /**
     * Lo que ve un job que se olvidó de fijar su tenant (`REQ-ORG-002` SP-3).
     */
    public function test_without_organization_empties_the_context(): void
    {
        $this->actingAsOrganization(Organization::factory()->create());

        $this->withoutOrganization();

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }
}
