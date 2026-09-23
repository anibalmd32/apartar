<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Catalog;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Queries\CatalogFacetsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las facetas presentes en un catálogo (`REQ-CAT-006` RB-3).
 *
 * Se entrega con `T-033` y la monta el panel de `T-034`.
 */
final class CatalogFacetsQueryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
    }

    /**
     * RB-3 · solo lo presente en **ese** catálogo, con su conteo: la
     * categoría que solo usa otro catálogo del mismo negocio no aparece.
     */
    public function test_it_offers_only_what_the_catalog_contains_with_counts(): void
    {
        $drills = Category::factory()->create(['name' => 'Taladros', 'position' => 2]);
        $saws = Category::factory()->create(['name' => 'Sierras', 'position' => 1]);
        $paint = Category::factory()->create(['name' => 'Pinturas', 'position' => 3]);

        $catalog = $this->catalogWith([
            Product::factory()->published()->inCategory($drills)->create(),
            Product::factory()->published()->inCategory($drills)->create(),
            Product::factory()->published()->inCategory($saws)->create(),
        ]);

        $this->catalogWith([Product::factory()->published()->inCategory($paint)->create()]);

        $facets = (new CatalogFacetsQuery)->forCatalog($catalog);

        $this->assertSame([
            ['slug' => $saws->slug, 'name' => 'Sierras', 'count' => 1],
            ['slug' => $drills->slug, 'name' => 'Taladros', 'count' => 2],
        ], $facets['categories']);
    }

    /**
     * Cuenta lo que la parrilla enseña: un `draft` o un archivado siguen en
     * el pivote y no cuentan, y una faceta que se queda en cero no aparece.
     */
    public function test_it_counts_only_visible_products_and_hides_empty_facets(): void
    {
        $drills = Category::factory()->create(['name' => 'Taladros']);
        $hidden = Category::factory()->create(['name' => 'Solo borradores']);

        $catalog = $this->catalogWith([
            Product::factory()->published()->inCategory($drills)->create(),
            Product::factory()->inCategory($drills)->create(),
            Product::factory()->archived()->inCategory($hidden)->create(),
            Product::factory()->scheduled()->inCategory($hidden)->create(),
        ]);

        $facets = (new CatalogFacetsQuery)->forCatalog($catalog);

        $this->assertSame([['slug' => $drills->slug, 'name' => 'Taladros', 'count' => 1]], $facets['categories']);
    }

    public function test_tags_come_alphabetically_with_their_counts(): void
    {
        $offer = Tag::factory()->create(['name' => 'Oferta']);
        $cordless = Tag::factory()->create(['name' => 'Inalámbrico']);
        Tag::factory()->create(['name' => 'Sin usar']);

        $first = Product::factory()->published()->create();
        $second = Product::factory()->published()->create();
        $first->tags()->attach([$offer->getKey(), $cordless->getKey()]);
        $second->tags()->attach($offer);

        $catalog = $this->catalogWith([$first, $second]);

        $this->assertSame([
            ['slug' => $cordless->slug, 'name' => 'Inalámbrico', 'count' => 1],
            ['slug' => $offer->slug, 'name' => 'Oferta', 'count' => 2],
        ], (new CatalogFacetsQuery)->forCatalog($catalog)['tags']);
    }

    /**
     * `REQ-CAT-006` RB-1 y SP-5 · la taxonomía de la vecina no aparece,
     * aunque se llame igual y tenga productos publicados.
     */
    public function test_the_neighbours_taxonomy_never_appears(): void
    {
        $this->withinOrganization($this->neighbourOrganization(), function (): void {
            $theirs = Category::factory()->create(['name' => 'Taladros']);
            $tag = Tag::factory()->create(['name' => 'Oferta']);
            $product = Product::factory()->published()->inCategory($theirs)->create();
            $product->tags()->attach($tag);
            $this->catalogWith([$product]);
        });

        $ours = Category::factory()->create(['name' => 'Taladros']);
        $catalog = $this->catalogWith([Product::factory()->published()->inCategory($ours)->create()]);

        $facets = (new CatalogFacetsQuery)->forCatalog($catalog);

        $this->assertSame([['slug' => $ours->slug, 'name' => 'Taladros', 'count' => 1]], $facets['categories']);
        $this->assertSame([], $facets['tags']);
    }

    /**
     * @param  list<Product>  $products
     */
    private function catalogWith(array $products): Catalog
    {
        $catalog = Catalog::factory()->published()->create();

        foreach ($products as $index => $product) {
            $catalog->products()->attach($product, ['position' => $index + 1]);
        }

        return $catalog;
    }
}
