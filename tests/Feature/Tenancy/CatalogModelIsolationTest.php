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

final class CatalogModelIsolationTest extends TestCase
{
    use RefreshDatabase;

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
