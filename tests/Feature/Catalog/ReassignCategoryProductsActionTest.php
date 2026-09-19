<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ReassignCategoryProductsAction;
use App\Actions\Catalog\UpsertCategoryAction;
use App\Domain\Catalog\CategoryInUseException;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La reasignación en lote de `REQ-BOF-023` RB-4, por debajo del HTTP.
 *
 * Lo que la prueba de la pantalla no ve: qué pasa con los productos que solo
 * existen para la base de datos, y que **nada de esto se audita**.
 */
final class ReassignCategoryProductsActionTest extends TestCase
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

    public function test_it_moves_every_product_and_says_how_many(): void
    {
        $from = Category::factory()->named('Ropa')->create();
        $to = Category::factory()->named('Textil')->create();

        Product::factory()->count(3)->inCategory($from)->create();

        $moved = $this->reassign()->handle($from, (int) $to->getKey(), $this->actor);

        $this->assertSame(3, $moved);
        $this->assertSame(0, $from->products()->count());
        $this->assertSame(3, $to->products()->count());
    }

    /**
     * **La línea que decide si la operación sirve para algo.**
     *
     * Lo que impide borrar la categoría es la FK `restrict`, y la FK cuenta
     * filas: un producto borrado lógicamente sigue apuntando aquí. Si la
     * reasignación lo dejara atrás, el borrado siguiente fallaría con un error
     * de base de datos **después** de que la persona hiciera exactamente lo
     * que se le pidió.
     */
    public function test_it_also_moves_the_products_in_the_recycle_bin(): void
    {
        $from = Category::factory()->named('Ropa')->create();
        $to = Category::factory()->named('Textil')->create();

        Product::factory()->inCategory($from)->create();
        Product::factory()->inCategory($from)->create()->delete();

        $moved = $this->reassign()->handle($from, (int) $to->getKey(), $this->actor);

        $this->assertSame(2, $moved);
        $this->assertSame(0, Product::withTrashed()->where('category_id', $from->getKey())->count());

        // Y por eso borrar después funciona de verdad.
        app(UpsertCategoryAction::class)->delete($from, $this->actor);

        $this->assertNull(Category::query()->find($from->getKey()));
    }

    /**
     * El archivado se mueve como cualquier otro: sigue teniendo categoría y
     * sigue impidiendo borrarla (`REQ-CAT-007` RB-7).
     */
    public function test_it_moves_archived_products(): void
    {
        $from = Category::factory()->named('Ropa')->create();
        $to = Category::factory()->named('Textil')->create();

        Product::factory()->inCategory($from)->archived()->create();

        $this->assertSame(1, $this->reassign()->handle($from, (int) $to->getKey(), $this->actor));
        $this->assertSame(1, $to->products()->count());
    }

    /**
     * SP-7 · la categoría de otra organización **no existe** desde aquí: la
     * acción la resuelve por consulta del tenant y el resultado es el `404`
     * del binding, no un `403` que confirmaría que existe.
     */
    public function test_a_target_of_another_organization_is_not_found(): void
    {
        $from = Category::factory()->named('Ropa')->create();

        Product::factory()->inCategory($from)->create();

        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Category => Category::factory()->named('Ajena')->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->reassign()->handle($from, (int) $foreign->getKey(), $this->actor);
        } finally {
            // Y el producto no se movió a ninguna parte.
            $this->assertSame(1, $from->products()->count());
        }
    }

    /**
     * Mover una categoría a sí misma no es un error que merezca reventar: es
     * una petición sin efecto, y la cifra devuelta no puede mentir sobre ello.
     */
    public function test_reassigning_to_itself_moves_nothing(): void
    {
        $category = Category::factory()->named('Ropa')->create();

        Product::factory()->count(2)->inCategory($category)->create();

        $this->assertSame(0, $this->reassign()->handle($category, (int) $category->getKey(), $this->actor));
        $this->assertSame(2, $category->products()->count());
    }

    /**
     * SP-1 · y el `422` que hace falta la reasignación, con su conteo dentro.
     */
    public function test_deleting_a_category_with_products_throws_with_the_count(): void
    {
        $category = Category::factory()->named('Ropa')->create();

        Product::factory()->count(2)->inCategory($category)->create();

        try {
            app(UpsertCategoryAction::class)->delete($category, $this->actor);
            $this->fail('La categoría con productos no debería poder borrarse.');
        } catch (CategoryInUseException $exception) {
            $this->assertSame(2, $exception->productsCount);
            $this->assertSame('category', $exception->field);
            $this->assertStringContainsString('2 productos', $exception->getMessage());
        }
    }

    /**
     * §4 · **nada de esto se audita.** La lista de `REQ-BOF-005` RB-1 es
     * cerrada y no tiene ningún evento de taxonomía: mover productos de una
     * categoría a otra no cambia lo que se vende, ni a qué precio, ni quién
     * puede verlo. El día que alguien cuele un `audit()` aquí, esto se pone
     * rojo.
     */
    public function test_the_taxonomy_writes_no_audit_entry(): void
    {
        $from = Category::factory()->named('Ropa')->create();
        $to = Category::factory()->named('Textil')->create();

        Product::factory()->inCategory($from)->create();

        $upsert = app(UpsertCategoryAction::class);

        $upsert->handle('Calzado', null, $this->actor);
        $this->reassign()->handle($from, (int) $to->getKey(), $this->actor);
        $upsert->delete($from, $this->actor);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    private function reassign(): ReassignCategoryProductsAction
    {
        return app(ReassignCategoryProductsAction::class);
    }
}
