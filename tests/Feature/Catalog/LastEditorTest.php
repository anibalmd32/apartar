<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\CatalogData;
use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Actions\Catalog\ReassignCategoryProductsAction;
use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Actions\Catalog\UpsertCatalogAction;
use App\Actions\Product\ChangeProductStatusAction;
use App\Actions\Product\ProductData;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Money\Money;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Quién modificó por última vez, contando la misma historia que la fecha
 * (`REQ-BOF-020` SP-7, `REQ-CAT-023` SP-4, `RecordsLastEditor`).
 *
 * Un caso por cada escritura que mueve `updated_at` en `products` y
 * `catalogs`: son las siete acciones que las tocan, y la que se olvide de
 * declarar a su autor se pone roja aquí.
 */
final class LastEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
        // `T-067` · crear, publicar y componer preguntan al plan.
        $this->subscribeWithoutLimits();

        $this->author = User::factory()->owner()->create(['name' => 'Autora']);
        $this->other = User::factory()->owner()->create(['name' => 'Otra persona']);
    }

    public function test_creating_and_editing_a_product_records_its_author(): void
    {
        $product = app(UpsertProductAction::class)->handle($this->productData('Taladro'), $this->author);
        $this->assertSame($this->author->getKey(), $product->refresh()->updated_by);

        app(UpsertProductAction::class)->handle($this->productData('Taladro percutor'), $this->other, $product);
        $this->assertSame($this->other->getKey(), $product->refresh()->updated_by);
    }

    public function test_a_status_change_records_its_author(): void
    {
        $product = $this->productBy($this->other);

        app(ChangeProductStatusAction::class)->archive($product, $this->author);

        $this->assertSame($this->author->getKey(), $product->refresh()->updated_by);
    }

    /**
     * Repetir un paso ya aplicado no escribe nada (`T-030`): ni la fecha ni el
     * autor se mueven, y las dos columnas siguen diciendo lo mismo.
     */
    public function test_a_step_that_changes_nothing_keeps_the_previous_author_and_date(): void
    {
        $product = $this->productBy($this->author);
        app(ChangeProductStatusAction::class)->archive($product, $this->author);
        $before = $product->refresh()->updated_at;

        Carbon::setTestNow(now()->addHour());
        app(ChangeProductStatusAction::class)->archive($product, $this->other);
        Carbon::setTestNow();

        $this->assertSame($this->author->getKey(), $product->refresh()->updated_by);
        $this->assertEquals($before, $product->updated_at);
    }

    /**
     * El `UPDATE` masivo mueve la fecha de todas las filas, así que mueve
     * también su autor: aquí no hay eventos de modelo que lo hagan solos.
     */
    public function test_reassigning_a_category_records_its_author_on_every_product(): void
    {
        $from = Category::factory()->create();
        $to = Category::factory()->create();
        $product = $this->productBy($this->other, ['category_id' => $from->getKey()]);

        app(ReassignCategoryProductsAction::class)->handle($from, (int) $to->getKey(), $this->author);

        $this->assertSame($this->author->getKey(), $product->refresh()->updated_by);
    }

    public function test_creating_and_editing_a_catalog_records_its_author(): void
    {
        $catalog = app(UpsertCatalogAction::class)->handle($this->catalogData('Ofertas'), $this->author);
        $this->assertSame($this->author->getKey(), $catalog->refresh()->updated_by);

        app(UpsertCatalogAction::class)->handle($this->catalogData('Ofertas de octubre'), $this->other, $catalog);
        $this->assertSame($this->other->getKey(), $catalog->refresh()->updated_by);
    }

    /**
     * **`REQ-CAT-023` SP-4 · componer es modificar el catálogo.** `T-031` hace
     * que añadir un producto mueva `updated_at` aunque el cambio viva en el
     * pivote; el autor es quien lo añadió, no quien renombró el catálogo.
     */
    public function test_composing_a_catalog_makes_the_composer_its_author(): void
    {
        $catalog = app(UpsertCatalogAction::class)->handle($this->catalogData('Ofertas'), $this->other);
        $product = $this->productBy($this->other);

        Carbon::setTestNow(now()->addMinute());
        app(SyncCatalogProductsAction::class)->handle($catalog, [(int) $product->getKey()], $this->author);
        Carbon::setTestNow();

        $catalog->refresh();
        $this->assertSame($this->author->getKey(), $catalog->updated_by);
        $this->assertSame('Ofertas', $catalog->name);
    }

    public function test_publishing_and_unpublishing_record_their_author(): void
    {
        PaymentAccount::factory()->create();
        $catalog = Catalog::factory()->create();
        $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

        app(PublishCatalogAction::class)->handle($catalog, new PublishCatalogData(isListed: true, expiresAt: null), $this->author);
        $this->assertSame($this->author->getKey(), $catalog->refresh()->updated_by);

        app(UnpublishCatalogAction::class)->handle($catalog, $this->other);
        $this->assertSame($this->other->getKey(), $catalog->refresh()->updated_by);
    }

    /**
     * Un guardado sin autor declarado —una factory, un comando— no borra ni
     * cambia el que había; y el declarado vale para **un** guardado.
     */
    public function test_a_save_without_a_declared_author_keeps_the_previous_one(): void
    {
        $product = $this->productBy($this->author);

        $product->forceFill(['stock' => 3])->save();
        $this->assertSame($this->author->getKey(), $product->refresh()->updated_by);

        $product->editedBy($this->other)->forceFill(['stock' => 4])->save();
        $product->forceFill(['stock' => 5])->save();
        $this->assertSame($this->other->getKey(), $product->refresh()->updated_by);
    }

    public function test_the_editor_relation_names_the_author(): void
    {
        $this->assertSame('Autora', $this->productBy($this->author)->editor?->name);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function productBy(User $author, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);
        $product->editedBy($author)->forceFill(['name' => $product->name.' ·'])->save();

        // La factory no ocupa su unidad de `products`; archivarlo la libera.
        $this->recalculateUsage();

        return $product->refresh();
    }

    private function productData(string $name): ProductData
    {
        return new ProductData(
            name: $name,
            sku: 'SKU-AUTOR',
            slug: null,
            description: 'Uno.',
            price: Money::fromDecimalString('250.00'),
            compareAtPrice: null,
            downPayment: Money::fromDecimalString('50.00'),
            installmentsCount: 4,
            frequencyDays: 15,
            stock: 10,
            categoryId: null,
            categoryName: null,
            tagIds: [],
            tagNames: [],
        );
    }

    private function catalogData(string $name): CatalogData
    {
        return new CatalogData(name: $name, slug: null, description: null, cover: null);
    }
}
