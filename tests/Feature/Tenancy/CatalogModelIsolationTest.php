<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aislamiento de los cuatro modelos que estrena `T-025` (`REQ-ORG-002`,
 * `REQ-QAX-008` RB-2).
 *
 * La convención del plan es que **cada modelo nuevo del tenant añade su caso
 * aquí**, y no es ceremonia: `Product` es la tabla con más consultas de todo
 * el sistema y `Category` viaja en la query string de un catálogo público, así
 * que las dos son sitios por los que una fuga entraría en la cara visible del
 * producto. `Catalog` ya tiene los suyos en `PublishedCatalogsTest`.
 *
 * Clase de PHPUnit y no sintaxis de Pest, por lo mismo que
 * `TenantModelIsolationTest`: usa los helpers de instancia del caso base.
 */
final class CatalogModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El global scope, sobre los cuatro. Si `Category::query()` dejara de
     * filtrar, el panel de filtros de un catálogo público ofrecería las
     * categorías del negocio de al lado.
     */
    public function test_each_model_is_only_visible_from_its_own_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn (): array => $this->seedOne());
        $this->withinOrganization($neighbour, fn (): array => $this->seedOne());

        $this->actingAsOrganization($own);

        $this->assertSame(1, Category::query()->count());
        $this->assertSame(1, Tag::query()->count());
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, ProductSpecification::query()->count());
        $this->assertTrue($this->hasNeighbourOrganization());
    }

    /**
     * RB-2 · el trait rellena `organization_id` en los cuatro; ningún código
     * lo asigna.
     */
    public function test_creating_fills_the_organization_on_every_model(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $models = $this->withinOrganization($organization, fn (): array => $this->seedOne());

        foreach ($models as $model) {
            $this->assertSame((int) $organization->getKey(), $model->getAttribute('organization_id'));
        }
    }

    /**
     * SP-3 · sin tenant fijado se lanza, no se devuelve todo.
     *
     * @param  class-string<Model>  $model
     */
    #[DataProvider('catalogModels')]
    public function test_querying_without_a_tenant_throws(string $model): void
    {
        $this->neighbourOrganization();
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        $model::query()->get();
    }

    /**
     * La guarda del trait sobre los cuatro: asignar a mano la organización
     * vecina lanza, en lugar de guardar una fila que ninguna consulta filtrada
     * volvería a ver del todo.
     *
     * **Este es el caso que detectaría una fuga real si mañana alguien quitara
     * el trait de `Product`.** Sin él, la fila se guardaría en el negocio
     * ajeno sin que nada fallara, y el producto acabaría en un catálogo que no
     * es el suyo.
     */
    public function test_assigning_the_neighbour_organization_by_hand_throws(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($own);

        foreach ($this->makeOneOfEach() as $model) {
            $model->setAttribute('organization_id', (int) $neighbour->getKey());

            try {
                $model->save();
                $this->fail($model::class.' guardó una fila de otra organización.');
            } catch (CrossTenantRelationException $exception) {
                $this->assertStringContainsString($model::class, $exception->getMessage());
            }
        }
    }

    /**
     * La otra mitad, y la que vale para una relación: lo que pertenece al
     * vecino **no existe** desde aquí, así que una relación que apuntara a una
     * de sus filas se lee vacía en lugar de cruzar la frontera.
     *
     * El producto del vecino no se alcanza desde esta organización ni por `id`
     * ni a través de la ficha técnica, que es el camino indirecto —el que no
     * pasa por `products` y por tanto el que se olvida—.
     */
    public function test_a_row_of_the_neighbour_is_unreachable_by_id_and_by_relation(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        [, , $foreignProduct, $foreignSpecification] = $this->withinOrganization(
            $neighbour,
            fn (): array => $this->seedOne(),
        );

        $this->actingAsOrganization($own);

        $this->assertNull(Product::query()->find($foreignProduct->getKey()));
        $this->assertNull(ProductSpecification::query()->find($foreignSpecification->getKey()));
        $this->assertSame(0, $foreignProduct->specifications()->count());
    }

    /**
     * La ficha técnica se siembra **desde su producto**, y por eso hereda su
     * organización. Sembrarla suelta con un `product_id` por defecto crearía
     * el producto fuera del `runFor()` del hijo y dejaría los dos en negocios
     * distintos: es el motivo por el que `ProductSpecificationFactory` no trae
     * ese valor por defecto.
     */
    public function test_a_specification_lands_in_the_organization_of_its_product(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $product = $this->withinOrganization(
            $organization,
            fn (): Product => Product::factory()
                ->has(ProductSpecification::factory()->count(2), 'specifications')
                ->create(),
        );

        $this->actingAsOrganization($organization);

        $this->assertSame(2, $product->specifications()->count());
        $this->assertSame(
            [(int) $organization->getKey()],
            ProductSpecification::query()
                ->pluck('organization_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function catalogModels(): array
    {
        return [
            'categorías' => [Category::class],
            'etiquetas' => [Tag::class],
            'productos' => [Product::class],
            'fichas técnicas' => [ProductSpecification::class],
        ];
    }

    /**
     * Una fila de cada modelo, ya persistida en la organización activa.
     *
     * @return array{Category, Tag, Product, ProductSpecification}
     */
    private function seedOne(): array
    {
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();

        $product = Product::factory()
            ->inCategory($category)
            ->has(ProductSpecification::factory(), 'specifications')
            ->create();

        $product->tags()->attach($tag);

        return [$category, $tag, $product, $product->specifications()->sole()];
    }

    /**
     * Una instancia sin guardar de cada modelo, con lo mínimo para que el
     * `INSERT` fuese válido: lo que se comprueba es que el trait lanza
     * **antes** de llegar a la base.
     *
     * @return list<Model>
     */
    private function makeOneOfEach(): array
    {
        $product = Product::factory()->create();

        return [
            Category::factory()->make(),
            Tag::factory()->make(),
            Product::factory()->make(),
            ProductSpecification::factory()->forProduct($product)->make(),
        ];
    }
}
