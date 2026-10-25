<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    public function test_the_money_columns_come_back_as_usd_money(): void
    {
        $product = Product::factory()->create([
            'price_cents' => 25_000,
            'down_payment_cents' => 5_000,
        ]);

        $product->refresh();

        $this->assertInstanceOf(Money::class, $product->price_cents);
        $this->assertSame(25_000, $product->price_cents->cents);
        $this->assertSame(Currency::USD, $product->price_cents->currency);
        $this->assertSame(5_000, $product->down_payment_cents->cents);
    }

    public function test_a_null_compare_at_price_comes_back_as_null(): void
    {
        $product = Product::factory()->create();

        $product->refresh();

        $this->assertNull($product->compare_at_price_cents);
    }

    public function test_a_present_compare_at_price_comes_back_as_money(): void
    {
        $product = Product::factory()->onSale(30_000)->create();

        $product->refresh();

        $this->assertInstanceOf(Money::class, $product->compare_at_price_cents);
        $this->assertSame(30_000, $product->compare_at_price_cents->cents);
    }

    public function test_persisting_a_non_usd_amount_throws(): void
    {
        $product = Product::factory()->create();

        $this->expectException(CurrencyMismatchException::class);

        $product->price_cents = new Money(25_000, Currency::VES);
        $product->save();
    }

    public function test_the_status_column_comes_back_as_the_enum(): void
    {
        $product = Product::factory()->published()->create();

        $product->refresh();

        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertTrue($product->status->isVisibleInCatalog());
    }

    public function test_deleting_a_product_is_logical(): void
    {
        $product = Product::factory()->create();

        $product->delete();

        $this->assertNull(Product::query()->find($product->getKey()));
        $this->assertNotNull(Product::query()->withTrashed()->find($product->getKey()));
    }

    public function test_it_relates_to_its_category_and_tags(): void
    {
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();

        $product = Product::factory()->inCategory($category)->create();
        $product->tags()->attach($tag);

        $this->assertTrue($product->category?->is($category));
        $this->assertTrue($product->tags()->sole()->is($tag));
        $this->assertTrue($category->products()->sole()->is($product));
        $this->assertTrue($tag->products()->sole()->is($product));
    }

    public function test_a_draft_product_may_have_no_category(): void
    {
        $product = Product::factory()->create();

        $this->assertNull($product->category_id);
        $this->assertNull($product->category);
    }

    public function test_it_relates_to_its_specifications(): void
    {
        $product = Product::factory()
            ->has(ProductSpecification::factory()->count(3), 'specifications')
            ->create();

        $this->assertSame(3, $product->specifications()->count());
        $this->assertTrue($product->specifications()->first()?->product->is($product));
    }

    public function test_attaching_to_a_catalog_writes_position_and_created_at(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->create();

        $catalog->products()->attach($product, ['position' => 3]);

        $this->assertDatabaseHas('catalog_product', [
            'catalog_id' => $catalog->getKey(),
            'product_id' => $product->getKey(),
            'position' => 3,
        ]);

        $this->assertSame(1, $catalog->products()->whereNotNull('catalog_product.created_at')->count());
        $this->assertFalse(Schema::hasColumn('catalog_product', 'updated_at'));
    }

    public function test_an_archived_product_keeps_its_catalog_relation(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->published()->create();

        $catalog->products()->attach($product, ['position' => 1]);

        $product->update(['status' => ProductStatus::Archived]);

        $this->assertSame(1, $catalog->products()->count());
        $this->assertSame(1, $product->catalogs()->count());
    }

    public function test_the_catalog_relation_does_not_filter_by_status(): void
    {
        $catalog = Catalog::factory()->published()->create();

        foreach ([ProductStatus::Draft, ProductStatus::Published, ProductStatus::Archived] as $position => $status) {
            $product = Product::factory()->create(['status' => $status]);
            $catalog->products()->attach($product, ['position' => $position + 1]);
        }

        $this->assertSame(3, $catalog->products()->count());
    }

    public function test_the_published_scope_leaves_out_drafts_and_archived(): void
    {
        $published = Product::factory()->published()->create();
        Product::factory()->create();
        Product::factory()->archived()->create();

        $this->assertSame(1, Product::query()->published()->count());
        $this->assertTrue(Product::query()->published()->sole()->is($published));
    }

    public function test_the_published_scope_hides_a_scheduled_product_until_its_date(): void
    {
        $scheduled = Product::factory()->scheduled()->create();

        $this->assertSame(ProductStatus::Published, $scheduled->status);
        $this->assertSame(0, Product::query()->published()->count());

        $this->travelTo(now()->addWeeks(2));

        $this->assertSame(1, Product::query()->published()->count());
    }

    public function test_the_published_scope_includes_a_product_published_exactly_now(): void
    {
        $this->freezeTime();

        Product::factory()->create(['status' => ProductStatus::Published, 'published_at' => now()]);

        $this->assertSame(1, Product::query()->published()->count());
    }

    public function test_the_published_scope_leaves_out_a_published_product_without_a_date(): void
    {
        Product::factory()->create(['status' => ProductStatus::Published, 'published_at' => null]);

        $this->assertSame(0, Product::query()->published()->count());
    }

    public function test_only_a_published_product_with_a_future_date_is_scheduled(): void
    {
        $this->assertTrue(Product::factory()->scheduled()->create()->isScheduled());
        $this->assertFalse(Product::factory()->published()->create()->isScheduled());
        $this->assertFalse(Product::factory()->create()->isScheduled());
        $this->assertFalse(Product::factory()->archived()->create()->isScheduled());
    }
}
