<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CatalogProductOrder;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogProductOrderTest extends TestCase
{
    use ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    public function test_one_save_updates_inserts_and_deletes(): void
    {
        $catalog = Catalog::factory()->create();

        [$stays, $leaves, $alsoStays] = $this->productIds(3);
        $joins = (int) Product::factory()->create()->getKey();

        $this->order()->apply($catalog, [$stays, $leaves, $alsoStays]);

        $before = $this->rowsIn($catalog);

        $this->travel(30)->days();

        $this->order()->apply($catalog, [$alsoStays, $joins, $stays]);

        $after = $this->rowsIn($catalog);

        $this->assertSame(1, $after[$alsoStays]['position']);
        $this->assertSame($before[$alsoStays]['created_at'], $after[$alsoStays]['created_at']);

        $this->assertSame(3, $after[$stays]['position']);
        $this->assertSame($before[$stays]['created_at'], $after[$stays]['created_at']);

        $this->assertSame(2, $after[$joins]['position']);
        $this->assertSame(now()->startOfSecond()->toDateTimeString(), $after[$joins]['created_at']);

        $this->assertArrayNotHasKey($leaves, $after);
        $this->assertSame(3, count($after));
    }

    public function test_positions_are_contiguous_after_any_save(): void
    {
        $catalog = Catalog::factory()->create();
        $ids = $this->productIds(4);

        $this->order()->apply($catalog, $ids);
        $this->assertSame([1, 2, 3, 4], $this->positionsIn($catalog));

        $this->order()->apply($catalog, [$ids[3], $ids[0]]);
        $this->assertSame([1, 2], $this->positionsIn($catalog));

        $this->order()->apply($catalog, []);
        $this->assertSame([], $this->positionsIn($catalog));
    }

    public function test_it_deduplicates_keeping_the_first_appearance(): void
    {
        $this->assertSame([3, 1, 2], $this->order()->deduplicate([3, 1, 3, 2, 1, 3]));
        $this->assertSame([], $this->order()->deduplicate([]));
    }

    public function test_saving_the_same_list_twice_changes_nothing(): void
    {
        $catalog = Catalog::factory()->create();
        $ids = $this->productIds(3);

        $this->order()->apply($catalog, $ids);

        $before = $this->rowsIn($catalog);

        $this->travel(30)->days();

        $this->order()->apply($catalog, $ids);

        $this->assertSame($before, $this->rowsIn($catalog));
    }

    public function test_it_only_touches_the_catalog_it_was_given(): void
    {
        $september = Catalog::factory()->create();
        $christmas = Catalog::factory()->create();

        $ids = $this->productIds(2);

        $this->order()->apply($september, $ids);
        $this->order()->apply($christmas, [$ids[1]]);

        $this->assertSame([1, 2], $this->positionsIn($september));
        $this->assertSame(2, count($this->rowsIn($september)));
        $this->assertSame([1], $this->positionsIn($christmas));
    }

    /**
     * @return list<int>
     */
    private function productIds(int $count): array
    {
        return Product::factory()
            ->count($count)
            ->create()
            ->map(fn (Product $product): int => (int) $product->getKey())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{position: int, created_at: string}>
     */
    private function rowsIn(Catalog $catalog): array
    {
        $rows = [];

        foreach (DB::table('catalog_product')->where('catalog_id', $catalog->getKey())->get() as $row) {
            $rows[(int) $row->product_id] = [
                'position' => (int) $row->position,
                'created_at' => (string) $row->created_at,
            ];
        }

        return $rows;
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

    private function order(): CatalogProductOrder
    {
        return app(CatalogProductOrder::class);
    }
}
