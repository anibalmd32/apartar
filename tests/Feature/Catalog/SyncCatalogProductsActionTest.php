<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SyncCatalogProductsActionTest extends TestCase
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
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_writes_contiguous_positions_from_one(): void
    {
        $catalog = Catalog::factory()->create();
        $products = Product::factory()->count(3)->create();

        $this->sync()->handle($catalog, $this->idsOf($products), $this->actor);

        $this->assertSame([1, 2, 3], $this->positionsIn($catalog));
        $this->assertSame($this->idsOf($products), $this->orderedIdsIn($catalog));
    }

    public function test_removing_one_in_the_middle_leaves_no_gap(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second, $third] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second, $third], $this->actor);
        $this->sync()->handle($catalog, [$first, $third], $this->actor);

        $this->assertSame([1, 2], $this->positionsIn($catalog));
        $this->assertSame([$first, $third], $this->orderedIdsIn($catalog));
    }

    public function test_duplicate_ids_are_deduplicated_keeping_the_first_one(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second, $third] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second, $first, $third, $second], $this->actor);

        $this->assertSame([$first, $second, $third], $this->orderedIdsIn($catalog));
        $this->assertSame([1, 2, 3], $this->positionsIn($catalog));
    }

    public function test_reordering_does_not_touch_the_pivot_created_at(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second, $third] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second, $third], $this->actor);

        $before = $this->createdAtIn($catalog);

        $this->travel(30)->days();

        $this->sync()->handle($catalog, [$third, $first, $second], $this->actor);

        $this->assertSame($before, $this->createdAtIn($catalog));
        $this->assertSame([$third, $first, $second], $this->orderedIdsIn($catalog));
        $this->assertSame([1, 2, 3], $this->positionsIn($catalog));
    }

    public function test_a_product_that_joins_today_gets_its_own_created_at(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second, $third] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        $this->travel(30)->days();

        $this->sync()->handle($catalog, [$first, $second, $third], $this->actor);

        $dates = $this->createdAtIn($catalog);

        $this->assertSame($dates[$first], $dates[$second]);
        $this->assertNotSame($dates[$first], $dates[$third]);
        $this->assertSame(now()->startOfSecond()->toDateTimeString(), $dates[$third]);
    }

    public function test_a_product_that_leaves_and_returns_starts_its_clock_again(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        $original = $this->createdAtIn($catalog)[$first];

        $this->travel(30)->days();

        $this->sync()->handle($catalog, [$second], $this->actor);
        $this->sync()->handle($catalog, [$second, $first], $this->actor);

        $this->assertNotSame($original, $this->createdAtIn($catalog)[$first]);
    }

    public function test_a_product_of_another_organization_is_refused(): void
    {
        $catalog = Catalog::factory()->create();
        $mine = Product::factory()->create();

        $neighbours = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Product => Product::factory()->create(),
        );

        $this->actingAsOrganization($this->organization);

        try {
            $this->sync()->handle($catalog, [(int) $mine->getKey(), (int) $neighbours->getKey()], $this->actor);

            $this->fail('El producto de otra organización tenía que rechazarse.');
        } catch (CrossTenantRelationException $exception) {
            $this->assertStringContainsString('organizaciones distintas', $exception->getMessage());
        }

        $this->assertSame(0, $catalog->products()->count());
        $this->assertSame(0, DB::table('catalog_product')->count());
    }

    public function test_an_unknown_id_is_refused_the_same_way(): void
    {
        $catalog = Catalog::factory()->create();

        $this->expectException(CrossTenantRelationException::class);

        $this->sync()->handle($catalog, [999_999], $this->actor);
    }

    public function test_a_refused_save_leaves_the_previous_composition_intact(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        try {
            $this->sync()->handle($catalog, [$second, 999_999], $this->actor);
        } catch (CrossTenantRelationException) {
        }

        $this->assertSame([$first, $second], $this->orderedIdsIn($catalog));
        $this->assertSame([1, 2], $this->positionsIn($catalog));
    }

    public function test_draft_products_are_accepted(): void
    {
        $catalog = Catalog::factory()->create();
        $draft = Product::factory()->create();

        $this->sync()->handle($catalog, [(int) $draft->getKey()], $this->actor);

        $this->assertSame(ProductStatus::Draft, $draft->refresh()->status);
        $this->assertSame(1, $catalog->products()->count());
    }

    public function test_adding_an_archived_product_is_refused(): void
    {
        $catalog = Catalog::factory()->create();
        $archived = Product::factory()->archived()->create(['name' => 'Taladro viejo']);

        try {
            $this->sync()->handle($catalog, [(int) $archived->getKey()], $this->actor);

            $this->fail('Añadir un archivado tenía que rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('products', $exception->errors());
            $this->assertStringContainsString('Taladro viejo', $exception->getMessage());
            $this->assertStringContainsString('archivado', $exception->getMessage());
        }

        $this->assertSame(0, $catalog->products()->count());
    }

    public function test_an_archived_product_that_was_already_in_can_stay(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        Product::query()->whereKey($first)->update(['status' => ProductStatus::Archived]);

        $this->sync()->handle($catalog, [$second, $first], $this->actor);

        $this->assertSame([$second, $first], $this->orderedIdsIn($catalog));
    }

    public function test_an_archived_product_may_still_be_removed(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        Product::query()->whereKey($first)->update(['status' => ProductStatus::Archived]);

        $this->sync()->handle($catalog, [$second], $this->actor);

        $this->assertSame([$second], $this->orderedIdsIn($catalog));
    }

    public function test_emptying_a_published_catalog_is_refused(): void
    {
        $catalog = Catalog::factory()->published()->create(['name' => 'Ofertas de septiembre']);
        $product = Product::factory()->published()->create();

        $this->sync()->handle($catalog, [(int) $product->getKey()], $this->actor);

        try {
            $this->sync()->handle($catalog, [], $this->actor);

            $this->fail('Vaciar un catálogo publicado tenía que rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('products', $exception->errors());
            $this->assertStringContainsString('Ofertas de septiembre', $exception->getMessage());
        }

        $this->assertSame(1, $catalog->products()->count());
    }

    public function test_emptying_a_draft_catalog_is_allowed(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->create();

        $this->sync()->handle($catalog, [(int) $product->getKey()], $this->actor);
        $this->sync()->handle($catalog, [], $this->actor);

        $this->assertSame(0, $catalog->products()->count());
    }

    public function test_a_product_may_live_in_several_catalogs_of_the_same_business(): void
    {
        $product = Product::factory()->published()->create();
        $other = Product::factory()->create();

        $september = Catalog::factory()->create();
        $christmas = Catalog::factory()->create();

        $this->sync()->handle($september, [(int) $other->getKey(), (int) $product->getKey()], $this->actor);
        $this->sync()->handle($christmas, [(int) $product->getKey()], $this->actor);

        $this->assertSame(2, $product->refresh()->catalogs()->count());
        $this->assertSame([2], $this->positionsFor($september, $product));
        $this->assertSame([1], $this->positionsFor($christmas, $product));
    }

    public function test_a_member_may_compose_a_catalog(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->create();
        $member = User::factory()->create();

        $this->sync()->handle($catalog, [(int) $product->getKey()], $member);

        $this->assertSame(1, $catalog->products()->count());
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $catalog = Catalog::factory()->create();

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->sync()->handle($catalog, [], $foreignOwner);
    }

    public function test_composing_writes_no_audit_entry(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->create();

        $this->sync()->handle($catalog, [(int) $product->getKey()], $this->actor);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_composing_marks_the_catalog_as_modified(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->create();

        $original = $catalog->updated_at;

        $this->travel(2)->days();

        $this->sync()->handle($catalog, [(int) $product->getKey()], $this->actor);

        $this->assertTrue($catalog->refresh()->updated_at?->greaterThan($original));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return list<int>
     */
    private function idsOf(mixed $products): array
    {
        return $products->map(fn (Product $product): int => (int) $product->getKey())->values()->all();
    }

    /**
     * @return list<int>
     */
    private function threeProducts(): array
    {
        return $this->idsOf(Product::factory()->count(3)->create());
    }

    /**
     * @return list<int>
     */
    private function positionsIn(Catalog $catalog): array
    {
        return DB::table('catalog_product')
            ->where('catalog_id', $catalog->getKey())
            ->orderBy('position')
            ->pluck('position')
            ->map(fn (mixed $position): int => (int) $position)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function orderedIdsIn(Catalog $catalog): array
    {
        return DB::table('catalog_product')
            ->where('catalog_id', $catalog->getKey())
            ->orderBy('position')
            ->pluck('product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function positionsFor(Catalog $catalog, Product $product): array
    {
        return DB::table('catalog_product')
            ->where('catalog_id', $catalog->getKey())
            ->where('product_id', $product->getKey())
            ->pluck('position')
            ->map(fn (mixed $position): int => (int) $position)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function createdAtIn(Catalog $catalog): array
    {
        $dates = [];

        foreach (DB::table('catalog_product')->where('catalog_id', $catalog->getKey())->get() as $row) {
            $dates[(int) $row->product_id] = (string) $row->created_at;
        }

        return $dates;
    }

    private function sync(): SyncCatalogProductsAction
    {
        return app(SyncCatalogProductsAction::class);
    }
}
