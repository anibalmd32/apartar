<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El aislamiento del inventario **por HTTP** (`REQ-BOF-020` SP-4 y SP-11,
 * `REQ-ORG-002` RB-7, `REQ-QAX-008` RB-2).
 *
 * `CatalogModelIsolationTest` ya cubre el modelo; esto cubre lo que `T-027`
 * estrena encima: seis rutas y, sobre todo, **la guarda de escritura de
 * `product_tag`**. Ese pivote no lleva `organization_id` —declarado así en
 * `T-024`— y el trait del tenant no cubre el caso: lanza cuando alguien asigna
 * a mano una organización ajena, no cuando una clave foránea apunta a otra.
 * La fila se guardaría con la organización activa y las dos pantallas
 * seguirían pareciendo correctas, porque cada una solo ve su mitad.
 */
final class ProductIsolationTest extends TestCase
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
     * **SP-4 · el mismo SKU en dos organizaciones está permitido**, y es la
     * regla que alguien «arregla» con un índice global. Dos negocios pueden
     * vender el mismo modelo con el mismo código de fábrica, y prohibirlo sería
     * que el primero que llega se quede con un código que no es suyo.
     */
    public function test_the_same_sku_is_allowed_in_two_organizations(): void
    {
        $this->withinOrganization(
            $this->neighbour,
            fn () => Product::factory()->create(['sku' => 'BOSCH-01', 'slug' => 'taladro']),
        );

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', [
                'name' => 'Taladro Bosch',
                'sku' => 'BOSCH-01',
                'description' => 'Un taladro.',
                'price' => '250.00',
                'compare_at_price' => '',
                'down_payment' => '50.00',
                'installments_count' => 4,
                'frequency_days' => 15,
                'stock' => 10,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Product::withoutTenantScope()->count());
        $this->assertSame(1, $this->countIn($this->organization));
        $this->assertSame(1, $this->countIn($this->neighbour));
    }

    /**
     * Y el enlace también es por organización: el mismo `slug` en dos negocios
     * no colisiona, al revés que el del catálogo, que es una URL pública.
     */
    public function test_the_same_slug_is_allowed_in_two_organizations(): void
    {
        $this->withinOrganization(
            $this->neighbour,
            fn () => Product::factory()->create(['sku' => 'VECINO', 'slug' => 'taladro-bosch']),
        );

        $own = $this->withinOrganization(
            $this->organization,
            fn (): Product => Product::factory()->create(['sku' => 'MIO', 'slug' => 'taladro-bosch']),
        );

        $this->assertSame('taladro-bosch', $own->slug);
        $this->assertSame(2, Product::withoutTenantScope()->where('slug', 'taladro-bosch')->count());
    }

    /**
     * La lectura: el listado de un negocio nunca enseña el inventario del otro.
     */
    public function test_the_listing_never_shows_the_neighbours_products(): void
    {
        $this->withinOrganization($this->neighbour, fn () => Product::factory()->create(['name' => 'Del vecino']));
        $this->withinOrganization($this->organization, fn () => Product::factory()->create(['name' => 'Mío']));

        $this->actingAs($this->owner())
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.name', 'Mío'));

        $this->assertTrue($this->hasNeighbourOrganization());
    }

    /**
     * **La fuga de escritura que este archivo existe para cerrar.**
     *
     * Etiquetar un producto con una etiqueta del negocio de al lado, escribiendo
     * su identificador a mano en la petición. Sin la guarda de la acción, la
     * fila del pivote se guardaría sin que nada fallara.
     */
    public function test_tagging_with_the_neighbours_tag_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', [
                'name' => 'Taladro Bosch',
                'sku' => 'BOSCH-01',
                'description' => 'Un taladro.',
                'price' => '250.00',
                'compare_at_price' => '',
                'down_payment' => '50.00',
                'installments_count' => 4,
                'frequency_days' => 15,
                'stock' => 10,
                'tag_ids' => [$foreign->getKey()],
            ])
            ->assertSessionHasErrors('tag_ids');

        $this->assertSame(0, $this->countIn($this->organization));
        $this->assertSame(0, DB::table('product_tag')->count());
    }

    /**
     * Y la misma frontera para la categoría: la acción la resuelve por consulta
     * del tenant, así que la ajena no existe.
     */
    public function test_assigning_the_neighbours_category_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): Category => Category::factory()->named('Del vecino')->create(),
        );

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', [
                'name' => 'Taladro Bosch',
                'sku' => 'BOSCH-01',
                'description' => 'Un taladro.',
                'price' => '250.00',
                'compare_at_price' => '',
                'down_payment' => '50.00',
                'installments_count' => 4,
                'frequency_days' => 15,
                'stock' => 10,
                'category_id' => $foreign->getKey(),
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->countIn($this->organization));
    }

    /**
     * **`REQ-BOF-021` SP-8 · el autocompletado de secciones no cruza
     * organizaciones.**
     *
     * Sugerir «Del vecino» porque la ferretería de al lado lo escribió no daría
     * un error: daría una pista de que ese negocio existe y de cómo describe lo
     * que vende. `SpecificationGroupsQueryTest` lo prueba sobre la consulta;
     * esto lo prueba **donde la persona lo vería**, que es la prop del
     * formulario.
     */
    public function test_the_group_autocomplete_never_offers_the_neighbours_sections(): void
    {
        $this->withinOrganization($this->neighbour, function (): void {
            $product = Product::factory()->create();

            ProductSpecification::factory()->forProduct($product)->create(['group' => 'Del vecino']);
        });

        $this->withinOrganization($this->organization, function (): void {
            $product = Product::factory()->create();

            ProductSpecification::factory()->forProduct($product)->create(['group' => 'Mía']);
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/productos/crear')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('specificationGroups', ['Mía']));

        $this->assertTrue($this->hasNeighbourOrganization());
    }

    /**
     * Y la ficha del producto de al lado tampoco se lee desde aquí: la tabla
     * es del tenant (`REQ-CAT-004` RB-6) y su global scope la filtra.
     */
    public function test_the_neighbours_specification_rows_are_unreachable(): void
    {
        $foreign = $this->withinOrganization($this->neighbour, function (): ProductSpecification {
            $product = Product::factory()->create();

            return ProductSpecification::factory()->forProduct($product)->create(['label' => 'Del vecino']);
        });

        $this->actingAsOrganization($this->organization);

        $this->assertNull(ProductSpecification::query()->find($foreign->getKey()));
        $this->assertSame(0, ProductSpecification::query()->count());
    }

    private function countIn(Organization $organization): int
    {
        return $this->withinOrganization($organization, fn (): int => Product::query()->count());
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }
}
