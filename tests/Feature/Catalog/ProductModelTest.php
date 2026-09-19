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

/**
 * El modelo `Product`: su dinero, su estado y sus cuatro relaciones
 * (`REQ-CAT-007`, `REQ-CAT-004`, `REQ-CAT-006`, `REQ-CAT-023`).
 *
 * Es el primer modelo del proyecto con columnas `*_cents`, así que aquí se
 * estrena `MoneyCast` sobre una tabla de verdad, y aquí es donde se fija que
 * un precio nunca sale como número suelto.
 *
 * Clase de PHPUnit y no sintaxis de Pest: necesita los helpers de instancia
 * del caso base para situarse en una organización.
 */
final class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    /**
     * `REQ-FX-001` RB-1 · el dinero sale como `Money` en USD, no como `int`.
     * Es lo que impide que alguien lo sume con un importe de otra moneda o lo
     * divida con una barra.
     */
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

    /**
     * SP-6 del cast · «sin precio de comparación» y «precio de comparación de
     * cero dólares» son cosas distintas, y la segunda se pinta como un
     * descuento del 100 %.
     */
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

    /**
     * `REQ-FX-001` RB-9 · la deuda es siempre en USD, así que persistir un
     * importe en bolívares lanza en lugar de dejar una fila indistinguible de
     * una correcta.
     */
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

    /**
     * `REQ-CAT-007` RB-2 · un producto con apartados nunca se borra
     * físicamente: el borrado es lógico y en la UI se llama «Archivar».
     */
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

    /**
     * `REQ-BOF-020` RB-5 · la categoría solo se exige para publicar, así que
     * un borrador la tiene nula y el modelo lo admite.
     */
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

    /**
     * `REQ-CAT-023` · el pivote guarda `position` y `created_at`, y **no**
     * `updated_at`: la tabla no tiene esa columna. Con `withTimestamps()` este
     * `attach()` intentaría escribirla y reventaría.
     */
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

        // `created_at` lo pone la base con `useCurrent`, no la relación.
        $this->assertSame(1, $catalog->products()->whereNotNull('catalog_product.created_at')->count());
        $this->assertFalse(Schema::hasColumn('catalog_product', 'updated_at'));
    }

    /**
     * **RB-7 de `REQ-CAT-007`, que es la regla que se desactiva sin ruido.**
     *
     * Los archivados conservan su relación con el catálogo para que
     * desarchivarlos los devuelva sin recomponer nada. Un `where('status', …)`
     * metido en la relación la desactivaría y ningún otro test fallaría: el
     * síntoma llegaría meses después, cuando un negocio desarchivara un
     * producto y descubriera que ya no está en sus catálogos.
     */
    public function test_an_archived_product_keeps_its_catalog_relation(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->published()->create();

        $catalog->products()->attach($product, ['position' => 1]);

        $product->update(['status' => ProductStatus::Archived]);

        $this->assertSame(1, $catalog->products()->count());
        $this->assertSame(1, $product->catalogs()->count());
    }

    /**
     * Y la misma regla mirada desde el otro lado: la relación devuelve los
     * tres estados, porque quien filtra es la consulta pública
     * (`REQ-CAT-021` RB-4) y no el modelo.
     */
    public function test_the_catalog_relation_does_not_filter_by_status(): void
    {
        $catalog = Catalog::factory()->published()->create();

        foreach ([ProductStatus::Draft, ProductStatus::Published, ProductStatus::Archived] as $position => $status) {
            $product = Product::factory()->create(['status' => $status]);
            $catalog->products()->attach($product, ['position' => $position + 1]);
        }

        $this->assertSame(3, $catalog->products()->count());
    }
}
