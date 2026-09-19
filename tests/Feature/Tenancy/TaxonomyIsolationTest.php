<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El aislamiento de la taxonomía **por HTTP** (`REQ-BOF-023` RB-1, SP-2, SP-7;
 * `REQ-QAX-008` RB-2).
 *
 * `CatalogModelIsolationTest` ya cubre los modelos; esto cubre lo que `T-026`
 * estrena encima de ellos: seis rutas de categorías y cuatro de etiquetas, con
 * dos organizaciones sembradas. La fuga que importa aquí no es leer de más
 * —el global scope lo impide— sino **escribir cruzado**: una reasignación que
 * aceptara una categoría ajena movería el inventario de un negocio al de otro
 * con un identificador escrito a mano en el formulario.
 */
final class TaxonomyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withoutOrganization();
    }

    /**
     * **RB-1 · el mismo nombre en dos organizaciones está permitido**, y es la
     * regla que hace de la taxonomía algo de cada negocio. Dos ferreterías
     * pueden llamar «Herramientas» a lo suyo sin que la primera reclame el
     * nombre.
     *
     * Lo que lo hace cierto es que `UNIQUE` incluye `organization_id` y que la
     * comprobación del `FormRequest` pregunta por el modelo, con su global
     * scope puesto. Si alguien la cambiara por un `Rule::unique` de tabla,
     * esto se pondría rojo.
     */
    public function test_the_same_category_name_is_allowed_in_two_organizations(): void
    {
        $this->withinOrganization($this->neighbour, fn () => Category::factory()->named('Herramientas')->create());

        $this->actingAs($this->owner())
            ->post('/backoffice/categorias', ['name' => 'Herramientas'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countIn($this->organization));
        $this->assertSame(1, $this->countIn($this->neighbour));
    }

    public function test_the_same_tag_name_is_allowed_in_two_organizations(): void
    {
        $this->withinOrganization($this->neighbour, fn () => Tag::factory()->named('Oferta')->create());

        $this->actingAs($this->owner())
            ->post('/backoffice/etiquetas', ['name' => 'Oferta'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Tag::withoutTenantScope()->count());
    }

    /**
     * Y la lectura: la pantalla de un negocio nunca enseña la taxonomía del
     * otro. Si `Category::query()` dejara de filtrar, el panel de filtros de
     * un catálogo público ofrecería las categorías del negocio de al lado.
     */
    public function test_the_listing_never_shows_the_neighbours_taxonomy(): void
    {
        $this->withinOrganization($this->neighbour, function (): void {
            Category::factory()->named('Del vecino')->create();
            Tag::factory()->named('Del vecino')->create();
        });

        $this->withinOrganization($this->organization, function (): void {
            Category::factory()->named('Mía')->create();
            Tag::factory()->named('Mía')->create();
        });

        $owner = $this->owner();

        $this->actingAs($owner)
            ->get('/backoffice/categorias')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('categories', 1)
                ->where('categories.0.name', 'Mía'));

        $this->actingAs($owner)
            ->get('/backoffice/etiquetas')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tags', 1)
                ->where('tags.0.name', 'Mía'));

        $this->assertTrue($this->hasNeighbourOrganization());
    }

    /**
     * **La fuga de escritura, que es la que de verdad podría llevarse algo por
     * delante.**
     *
     * La reasignación recibe un identificador del formulario. Con un `find()`
     * sin filtrar, escribir a mano el de una categoría del negocio de al lado
     * movería el inventario entero de una organización a otra — y las dos
     * pantallas seguirían pareciendo correctas, porque cada una solo ve su
     * mitad. La acción lo resuelve por consulta del tenant, así que responde
     * `404` (SP-7).
     */
    public function test_reassigning_to_the_neighbours_category_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): Category => Category::factory()->named('Del vecino')->create(),
        );

        $own = $this->withinOrganization($this->organization, function (): Category {
            $category = Category::factory()->named('Mía')->create();

            Product::factory()->count(2)->inCategory($category)->create();

            return $category;
        });

        $this->actingAs($this->owner())
            ->post("/backoffice/categorias/{$own->getKey()}/reasignar", ['category_id' => $foreign->getKey()])
            ->assertNotFound();

        $this->assertSame(2, $this->withinOrganization($this->organization, fn (): int => $own->products()->count()));
        $this->assertSame(0, $this->withinOrganization($this->neighbour, fn (): int => $foreign->products()->count()));
    }

    /**
     * Y el guardado del orden tampoco cruza: un identificador ajeno en la
     * lista no reordena nada del vecino.
     */
    public function test_reordering_ignores_the_neighbours_categories(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): Category => Category::factory()->named('Del vecino')->create(['position' => 1]),
        );

        $own = $this->withinOrganization(
            $this->organization,
            fn (): Category => Category::factory()->named('Mía')->create(['position' => 1]),
        );

        $this->actingAs($this->owner())
            ->patch('/backoffice/categorias/orden', ['categories' => [$foreign->getKey(), $own->getKey()]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withinOrganization($this->organization, fn (): int => (int) $own->refresh()->position));
        $this->assertSame(1, $this->withinOrganization($this->neighbour, fn (): int => (int) $foreign->refresh()->position));
    }

    private function countIn(Organization $organization): int
    {
        return $this->withinOrganization($organization, fn (): int => Category::query()->count());
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }
}
