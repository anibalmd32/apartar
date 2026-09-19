<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\UpsertCategoryAction;
use App\Domain\Catalog\CategoryInUseException;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El alta, la edición y la baja de una categoría, por debajo del HTTP
 * (`REQ-BOF-023`).
 *
 * Lo que la prueba de la pantalla no ve: la generación del slug con sus
 * choques, la contigüidad de `position` al crear y al borrar, y que la policy
 * es de los dos roles.
 */
final class UpsertCategoryActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_creates_the_category_with_a_derived_slug_at_the_end(): void
    {
        $this->upsert()->handle('Herramientas', null, $this->actor);
        $second = $this->upsert()->handle('Electrodomésticos', null, $this->actor);

        $this->assertSame('electrodomesticos', $second->slug);
        $this->assertSame(2, $second->position);
    }

    /**
     * RB-3 · el enlace se puede pedir a mano al crear, y se normaliza: lo que
     * se guarda tiene que seguir siendo un segmento de URL.
     */
    public function test_a_requested_slug_is_normalised(): void
    {
        $category = $this->upsert()->handle('Ropa', 'Ropa De Niño', $this->actor);

        $this->assertSame('ropa-de-nino', $category->slug);
    }

    /**
     * Dos nombres distintos con el mismo slug: el segundo se desambigua con
     * sufijo. Rechazarlo obligaría a renombrar una categoría legítima por un
     * detalle del que nadie ha hablado nunca.
     */
    public function test_a_colliding_slug_gets_a_suffix(): void
    {
        $this->upsert()->handle('Ropa niño', null, $this->actor);
        $second = $this->upsert()->handle('Ropa-niño', null, $this->actor);

        $this->assertSame('ropa-nino', Category::query()->where('name', 'Ropa niño')->sole()->slug);
        $this->assertSame('ropa-nino-2', $second->slug);
    }

    /**
     * Un nombre del que no queda nada que convertir no puede dejar el slug
     * vacío: dos categorías así colisionarían entre ellas.
     */
    public function test_a_name_without_letters_still_gets_a_slug(): void
    {
        $first = $this->upsert()->handle('★', null, $this->actor);
        $second = $this->upsert()->handle('☆', null, $this->actor);

        $this->assertNotSame('', $first->slug);
        $this->assertNotSame($first->slug, $second->slug);
    }

    /**
     * RB-6 · renombrar no toca el enlace, ni siquiera cuando el nombre nuevo
     * daría otro slug.
     */
    public function test_renaming_keeps_the_slug(): void
    {
        $category = $this->upsert()->handle('Electrodomesitos', null, $this->actor);

        $this->upsert()->handle('Electrodomésticos', null, $this->actor, $category);

        $this->assertSame('Electrodomésticos', $category->refresh()->name);
        $this->assertSame('electrodomesitos', $category->slug);
    }

    /**
     * SP-3 · con productos publicados el enlace se cierra, y el rechazo no
     * deja **nada** escrito: el guardado es todo o nada.
     */
    public function test_a_locked_slug_rejects_the_whole_update(): void
    {
        $category = $this->upsert()->handle('Ropa', null, $this->actor);

        Product::factory()->inCategory($category)->published()->create();

        try {
            $this->upsert()->handle('Ropa de niño', 'ropa-nino', $this->actor, $category);
            $this->fail('El enlace bloqueado debería rechazar el guardado.');
        } catch (CategoryInUseException $exception) {
            $this->assertSame('slug', $exception->field);
        }

        $category->refresh();

        $this->assertSame('Ropa', $category->name);
        $this->assertSame('ropa', $category->slug);
    }

    /**
     * Y el estado del producto es lo que decide, no su fecha: un `published`
     * programado para la semana que viene ya está comprometido a publicarse
     * (`REQ-CAT-007` RB-5), así que su enlace tampoco se toca.
     */
    public function test_a_scheduled_product_also_locks_the_slug(): void
    {
        $category = $this->upsert()->handle('Ropa', null, $this->actor);

        Product::factory()->inCategory($category)->scheduled()->create();

        $this->expectException(CategoryInUseException::class);

        $this->upsert()->handle('Ropa', 'ropa-nino', $this->actor, $category);
    }

    /**
     * §5.5 · borrar deja las posiciones contiguas. Sin esto, borrar la
     * segunda de tres dejaría 1 y 3, y el siguiente arrastre calcularía sobre
     * una numeración que no coincide con lo que se ve.
     */
    public function test_deleting_keeps_the_positions_contiguous(): void
    {
        $first = $this->upsert()->handle('A', null, $this->actor);
        $second = $this->upsert()->handle('B', null, $this->actor);
        $third = $this->upsert()->handle('C', null, $this->actor);

        $this->upsert()->delete($second, $this->actor);

        $this->assertSame(1, $first->refresh()->position);
        $this->assertSame(2, $third->refresh()->position);
    }

    /**
     * SP-1 · y con productos dentro no se borra, incluidos los que solo ve la
     * base de datos: la FK `restrict` cuenta filas, así que comprobar con el
     * conteo de la pantalla daría un error de base de datos en lugar del
     * `422`.
     */
    public function test_a_category_with_a_trashed_product_still_cannot_be_deleted(): void
    {
        $category = $this->upsert()->handle('Ropa', null, $this->actor);

        Product::factory()->inCategory($category)->create()->delete();

        $this->expectException(CategoryInUseException::class);

        $this->upsert()->delete($category, $this->actor);
    }

    /**
     * De los dos roles: la taxonomía es operación diaria
     * (`REQ-ORG-003` RB-1).
     */
    public function test_a_member_may_manage_the_taxonomy(): void
    {
        $member = User::factory()->create();

        $this->assertSame(OrganizationRole::Member, $member->role);
        $this->assertSame('Del empleado', $this->upsert()->handle('Del empleado', null, $member)->name);
    }

    /**
     * Y la otra mitad de la policy, la que el binding no cubre: el `owner` del
     * negocio de al lado no mantiene esta taxonomía.
     */
    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->upsert()->handle('Ajena', null, $foreignOwner);
    }

    private function upsert(): UpsertCategoryAction
    {
        return app(UpsertCategoryAction::class);
    }
}
