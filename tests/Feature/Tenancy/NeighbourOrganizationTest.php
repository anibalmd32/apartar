<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
