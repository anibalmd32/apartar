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
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReassignCategoryProductsActionTest extends TestCase
{
    use ReusesMigratedDatabase;

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

    public function test_it_also_moves_the_products_in_the_recycle_bin(): void
    {
        $from = Category::factory()->named('Ropa')->create();
        $to = Category::factory()->named('Textil')->create();

        Product::factory()->inCategory($from)->create();
        Product::factory()->inCategory($from)->create()->delete();

        $moved = $this->reassign()->handle($from, (int) $to->getKey(), $this->actor);

        $this->assertSame(2, $moved);
        $this->assertSame(0, Product::withTrashed()->where('category_id', $from->getKey())->count());

        app(UpsertCategoryAction::class)->delete($from, $this->actor);

        $this->assertNull(Category::query()->find($from->getKey()));
    }

    public function test_it_moves_archived_products(): void
    {
        $from = Category::factory()->named('Ropa')->create();
        $to = Category::factory()->named('Textil')->create();

        Product::factory()->inCategory($from)->archived()->create();

        $this->assertSame(1, $this->reassign()->handle($from, (int) $to->getKey(), $this->actor));
        $this->assertSame(1, $to->products()->count());
    }

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
            $this->assertSame(1, $from->products()->count());
        }
    }

    public function test_reassigning_to_itself_moves_nothing(): void
    {
        $category = Category::factory()->named('Ropa')->create();

        Product::factory()->count(2)->inCategory($category)->create();

        $this->assertSame(0, $this->reassign()->handle($category, (int) $category->getKey(), $this->actor));
        $this->assertSame(2, $category->products()->count());
    }

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
