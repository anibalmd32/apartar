<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Catalog\PublishedCatalogs;
use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El relevo de SP-5 (`REQ-ORG-006`), ya cerrado.
 *
 * La regla la sostiene `UpdateOrganizationAction`: **cambiar el slug con
 * catálogos publicados da `422`**, porque rompería los enlaces de la vitrina ya
 * compartidos — y el caso de uso de la vitrina es literalmente pegar esa URL
 * en una bio de Instagram.
 *
 * Hasta `T-024` esta clase respondía que no había ninguno, lo cual era cierto
 * porque la tabla no existía, y **lanzaba** en cuanto la tabla apareciera para
 * que el relevo no se quedara abierto ocho tareas sin un test en rojo. Ahora
 * consulta de verdad, y lo que se comprueba aquí son las tres respuestas que
 * tiene que dar —incluida la que de verdad importa: que el catálogo publicado
 * **de otra organización** no cuenta—.
 *
 * Va como clase de PHPUnit y no con la sintaxis de Pest porque usa los helpers
 * de instancia del caso base (`withinOrganization`, `neighbourOrganization`),
 * y PHPStan nivel 6 no resuelve el `$this` de las *closures* de Pest.
 */
final class PublishedCatalogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_answers_false_when_the_organization_has_no_catalogs(): void
    {
        $organization = Organization::factory()->create();

        $this->assertFalse($this->existFor($organization));
    }

    /**
     * Un borrador no cuenta: su URL no ha circulado, así que cambiar el slug
     * del negocio no rompe ningún enlace.
     */
    public function test_a_draft_catalog_does_not_count(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->create());

        $this->assertFalse($this->existFor($organization));
    }

    public function test_a_published_catalog_counts(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create());

        $this->assertTrue($this->existFor($organization));
    }

    /**
     * **La mitad que el global scope tiene que estar haciendo.**
     *
     * El catálogo publicado del negocio vecino no puede bloquear el cambio de
     * slug de este. Si esta prueba fallara, la consulta estaría leyendo la
     * tabla entera —y el síntoma sería un `422` que nadie sabe explicar: «no
     * puedes cambiar tu enlace» sobre un negocio que no ha publicado nada—.
     */
    public function test_a_published_catalog_of_another_organization_does_not_count(): void
    {
        $organization = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->published()->create());

        $this->assertFalse($this->existFor($organization));
        $this->assertTrue($this->existFor($neighbour));
    }

    /**
     * Un catálogo **expirado sigue contando**: su URL circuló igual y su
     * `published_at` no es nulo. Lo que devuelve un catálogo a borrador es
     * despublicarlo (`REQ-CAT-022` RB-7), no que pase su fecha.
     */
    public function test_an_expired_catalog_still_counts(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create([
            'expires_at' => now()->subDay(),
        ]));

        $this->assertTrue($this->existFor($organization));
    }

    /**
     * Y se sigue pudiendo sustituir, que es lo que permite a
     * `UpdateOrganizationActionTest` ejercitar el `422` sin montar un catálogo.
     */
    public function test_it_can_still_be_substituted(): void
    {
        $withCatalogs = new class extends PublishedCatalogs
        {
            public function exist(): bool
            {
                return true;
            }
        };

        $this->assertTrue($withCatalogs->exist());
    }

    private function existFor(Organization $organization): bool
    {
        return $this->withinOrganization(
            $organization,
            fn (): bool => (new PublishedCatalogs)->exist(),
        );
    }
}
