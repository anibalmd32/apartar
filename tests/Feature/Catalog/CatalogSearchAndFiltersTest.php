<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tag;
use App\Support\CatalogCursor;
use App\Support\FullTextTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Búsqueda, filtros y orden de la vista pública (`REQ-CAT-002`,
 * `REQ-CAT-006`, `REQ-CAT-021` RB-5).
 *
 * ## Aquí la búsqueda es siempre `LIKE`, y está dicho
 *
 * El índice `FULLTEXT` de InnoDB **solo ve filas confirmadas**, y este archivo
 * corre dentro de la transacción de `RefreshDatabase`: todo `MATCH` de aquí
 * devuelve cero y la consulta degrada a `LIKE`, que es justo lo que RB-2 manda
 * hacer con cero resultados. Lo que se prueba aquí es por tanto el modo `LIKE`
 * —nombre, SKU, acentos, términos cortos— y todo lo que no depende del modo:
 * el saneado, la acotación, la fuga, los filtros, el orden y el cursor.
 *
 * El modo `FULLTEXT` —relevancia, descripción, operadores ante el índice de
 * verdad— se prueba con filas confirmadas en `CatalogFullTextSearchTest`, en la
 * ronda en serie. Un test que diera por probado el índice desde aquí estaría
 * probando el `LIKE` con otro nombre.
 */
final class CatalogSearchAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $neighbour;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        // REQ-QAX-003 RB-10: la vecina tiene lo mismo con los mismos nombres,
        // para que una fuga tenga algo que encontrar.
        $this->neighbour = $this->neighbourOrganization();
        $this->subscribe($this->neighbour);
        $this->withinOrganization($this->neighbour, function (): void {
            $catalog = Catalog::factory()->published()->create();
            $category = Category::factory()->named('Taladros')->create();
            $this->attach($catalog, [$this->product('Taladro de la vecina', $category)]);
        });

        $this->organization = Organization::factory()->create();
        $this->subscribe($this->organization);
        $this->catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());
    }

    // ------------------------------------------------------------------
    // La búsqueda (REQ-CAT-002)
    // ------------------------------------------------------------------

    public function test_it_finds_by_name_and_by_sku(): void
    {
        $this->stock([
            ['Taladro percutor', ['sku' => 'TAL-500']],
            ['Sierra circular', ['sku' => 'SIE-200']],
        ]);

        $this->assertSame(['taladro-percutor'], $this->slugs(['q' => 'percutor']));
        $this->assertSame(['sierra-circular'], $this->slugs(['q' => 'SIE-200']));
        $this->assertSame(['sierra-circular'], $this->slugs(['q' => 'sie 200']));
    }

    /** SP-4 · con y sin acentos, en los dos sentidos, por la collation. */
    public function test_accents_match_with_and_without_them(): void
    {
        $this->stock([['Cepillo eléctrico'], ['Motor diesel']]);

        $this->assertSame(['cepillo-electrico'], $this->slugs(['q' => 'electrico']));
        $this->assertSame(['motor-diesel'], $this->slugs(['q' => 'mótor díesel']));
    }

    /**
     * RB-2 · lo que el índice no ve lo encuentra el `LIKE`: dos letras, y un
     * trozo del medio de una palabra.
     */
    public function test_short_terms_and_word_fragments_are_found(): void
    {
        $this->stock([['Soporte TV de pared'], ['Taladro percutor']]);

        $this->assertSame(['soporte-tv-de-pared'], $this->slugs(['q' => 'tv']));
        $this->assertSame(['taladro-percutor'], $this->slugs(['q' => 'ladro']));
    }

    /**
     * RB-7 y SP-5 · un operador no cambia la consulta: `-percutor` no excluye,
     * y `+"kit"` no es una frase obligatoria. Se buscan las palabras.
     */
    public function test_boolean_operators_are_searched_and_not_obeyed(): void
    {
        $this->stock([['Taladro percutor'], ['Kit barato de brocas'], ['Sierra']]);

        $this->assertSame(['taladro-percutor'], $this->slugs(['q' => 'taladro -percutor']));
        $this->assertSame(['kit-barato-de-brocas'], $this->slugs(['q' => '+"kit" -barato']));
        $this->assertSame(['taladro-percutor'], $this->slugs(['q' => '"taladro (percutor']));
    }

    /** SP-1 · solo signos o solo palabras vacías: el parámetro se ignora. */
    public function test_a_term_that_sanitizes_to_nothing_is_ignored(): void
    {
        $this->stock([['Taladro'], ['Sierra']]);

        foreach (['+-*"()<>~@', 'de la con'] as $term) {
            $this->visit(['q' => $term])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.q', null)
                ->has('products.data', 2));
        }
    }

    /** SP-3 · más de cien caracteres se recortan sin error. */
    public function test_a_long_term_is_truncated_without_error(): void
    {
        $this->stock([['Taladro']]);

        $response = $this->visit(['q' => 'taladro '.str_repeat('x', 300)])->assertOk();

        $this->assertSame(FullTextTerm::MAX_LENGTH, mb_strlen((string) $this->props($response)['filters']['q']));
    }

    /** SP-6 · lo despublicado no aparece aunque responda al término. */
    public function test_unpublished_products_are_not_found(): void
    {
        $this->withinOrganization($this->organization, fn () => $this->attach($this->catalog, [
            $this->product('Taladro publicado'),
            Product::factory()->create(['name' => 'Taladro borrador', 'slug' => 'taladro-borrador']),
            Product::factory()->archived()->create(['name' => 'Taladro archivado', 'slug' => 'taladro-archivado']),
        ]));

        $this->assertSame(['taladro-publicado'], $this->slugs(['q' => 'taladro']));
    }

    /**
     * Test de **acotación**: un producto de otro catálogo del mismo negocio no
     * aparece. Si fallara, faltaría el `JOIN` con el catálogo.
     */
    public function test_a_product_of_another_catalog_of_the_same_business_is_not_found(): void
    {
        $this->stock([['Taladro de este catálogo']]);
        $this->withinOrganization($this->organization, fn () => $this->attach(
            Catalog::factory()->published()->create(),
            [$this->product('Taladro de otra campaña')],
        ));

        $this->assertSame(['taladro-de-este-catalogo'], $this->slugs(['q' => 'taladro']));
    }

    /**
     * Test de **fuga**: el producto de la vecina no aparece ni enlazándolo a
     * mano en el pivote de este catálogo. Si fallara, sería el aislamiento lo
     * roto, no la búsqueda.
     */
    public function test_a_product_of_another_organization_is_not_found_even_linked_by_hand(): void
    {
        $this->stock([['Taladro propio', [], 'Taladros']]);
        $foreign = $this->withinOrganization($this->neighbour, fn (): int => (int) Product::query()->value('id'));
        DB::table('catalog_product')->insert(['catalog_id' => $this->catalog->getKey(), 'product_id' => $foreign, 'position' => 99]);

        $this->assertSame(['taladro-propio'], $this->slugs(['q' => 'taladro']));
        $this->assertSame(['taladro-propio'], $this->slugs([]));

        $facets = $this->props($this->visit([]))['facets'];
        $this->assertSame(['Taladros'], array_column($facets['categories'], 'name'));
        $this->assertSame([1], array_column($facets['categories'], 'count'));
    }

    /**
     * RB-4 · sin índice que puntúe —aquí siempre, ver arriba— no hay
     * relevancia: el término sin `sort` ordena por el orden curado.
     */
    public function test_a_like_search_without_sort_follows_the_curated_order(): void
    {
        $this->stock([['Taladro B'], ['Sierra'], ['Taladro A']]);

        $this->assertSame(['taladro-b', 'taladro-a'], $this->slugs(['q' => 'taladro']));
        $this->assertSame(['taladro-a', 'taladro-b'], $this->slugs(['q' => 'taladro', 'sort' => 'name']));
    }

    /** RB-3 · la búsqueda se combina con la categoría. */
    public function test_search_combines_with_the_category(): void
    {
        $this->stock([['Taladro rojo', [], 'Herramientas'], ['Pintura roja', [], 'Pinturas'], ['Sierra', [], 'Herramientas']]);

        $this->assertSame(['taladro-rojo'], $this->slugs(['q' => 'roj', 'category' => 'herramientas']));
    }

    // ------------------------------------------------------------------
    // Las facetas y los filtros (REQ-CAT-006)
    // ------------------------------------------------------------------

    /**
     * RB-3 · el panel ofrece lo presente en **este** catálogo, con su conteo:
     * ni la categoría del negocio que solo está en otra campaña, ni la de un
     * producto en borrador.
     */
    public function test_the_facets_are_only_what_this_catalog_shows(): void
    {
        $this->stock([['Taladro', [], 'Herramientas', ['oferta']], ['Sierra', [], 'Herramientas'], ['Broca', [], 'Accesorios', ['oferta', 'nuevo']]]);
        $this->withinOrganization($this->organization, function (): void {
            $this->attach(Catalog::factory()->published()->create(), [$this->product('Pintura', Category::factory()->named('Pinturas')->create())]);
            $this->attach($this->catalog, [Product::factory()->create(['category_id' => Category::factory()->named('Borradores')->create()->getKey()])]);
        });

        $facets = $this->props($this->visit([]))['facets'];

        $this->assertEqualsCanonicalizing(
            [['Herramientas', 2], ['Accesorios', 1]],
            array_map(fn (array $facet): array => [$facet['name'], $facet['count']], $facets['categories']),
        );
        $this->assertSame([['nuevo', 1], ['oferta', 2]], array_map(fn (array $facet): array => [$facet['name'], $facet['count']], $facets['tags']));
    }

    /**
     * §6 · el número de cada faceta es **exactamente** lo que devuelve
     * pulsarla, recorriendo todos los lotes: una categoría de trece productos
     * ocupa dos.
     */
    public function test_every_facet_count_matches_what_its_filter_returns(): void
    {
        $rows = [];
        foreach (range(1, CatalogCursor::PER_PAGE + 1) as $n) {
            $rows[] = ['Taladro '.$n, [], 'Taladros', $n % 2 === 0 ? ['oferta'] : []];
        }
        $rows[] = ['Sierra', [], 'Sierras', ['oferta', 'nuevo']];
        $this->stock($rows);
        $this->withinOrganization($this->organization, fn () => $this->attach($this->catalog, [
            Product::factory()->archived()->create(['category_id' => Category::query()->where('slug', 'taladros')->value('id')]),
        ]));

        $facets = $this->props($this->visit([]))['facets'];

        foreach ($facets['categories'] as $facet) {
            $this->assertCount($facet['count'], $this->allSlugs(['category' => $facet['slug']]), 'Categoría '.$facet['name']);
        }
        foreach ($facets['tags'] as $facet) {
            $this->assertCount($facet['count'], $this->allSlugs(['tags' => [$facet['slug']]]), 'Etiqueta '.$facet['name']);
        }
    }

    /** RB-4 · las etiquetas suman (`OR`); la categoría es una. */
    public function test_tags_are_or_and_the_category_is_single(): void
    {
        $this->stock([['Uno', [], 'A', ['oferta']], ['Dos', [], 'B', ['nuevo']], ['Tres', [], 'A'], ['Cuatro', [], 'B', ['oferta', 'nuevo']]]);

        $this->assertSame(['uno', 'dos', 'cuatro'], $this->slugs(['tags' => ['oferta', 'nuevo']]));
        $this->assertSame(['uno', 'tres'], $this->slugs(['category' => 'a']));
        $this->assertSame(['dos', 'cuatro'], $this->slugs(['category' => 'b', 'tags' => ['nuevo']]));
    }

    /**
     * SP-1, SP-4, SP-5 · lo que el panel no ofrece no se aplica: el resto del
     * enlace sigue funcionando, sin `404`, y la página lo dice.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unknownFilters(): iterable
    {
        yield 'una categoría que no existe' => [['category' => 'no-existe']];
        yield 'una categoría de la vecina' => [['category' => 'taladros-vecina']];
        yield 'una del negocio, pero no de este catálogo' => [['category' => 'pinturas']];
        yield 'una etiqueta borrada' => [['tags' => ['borrada']]];
        yield 'una categoría con forma rara' => [['category' => '<script>']];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('unknownFilters')]
    public function test_a_filter_that_is_not_in_this_catalog_is_ignored_with_a_notice(array $query): void
    {
        $this->stock([['Taladro', [], 'Herramientas'], ['Sierra', [], 'Herramientas']]);
        $this->withinOrganization($this->neighbour, fn () => Category::factory()->named('Taladros vecina')->create());
        $this->withinOrganization($this->organization, fn () => $this->attach(
            Catalog::factory()->published()->create(),
            [$this->product('Pintura', Category::factory()->named('Pinturas')->create())],
        ));

        $this->visit($query)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.ignored', true)
            ->where('filters.category', null)
            ->where('filters.tags', [])
            ->has('products.data', 2));
    }

    public function test_a_clean_visit_carries_no_notice(): void
    {
        $this->stock([['Taladro', [], 'Herramientas']]);

        $this->visit(['category' => 'herramientas'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', ['q' => null, 'category' => 'herramientas', 'tags' => [], 'sort' => null, 'ignored' => false]));
    }

    // ------------------------------------------------------------------
    // El orden y el cursor (RB-5, REQ-CAT-021 RB-6, SP-9)
    // ------------------------------------------------------------------

    /**
     * RB-5 · los cinco órdenes. Los precios, los nombres y las fechas de
     * entrada al catálogo van cada uno en un orden distinto del curado.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sorts(): iterable
    {
        yield 'curated' => ['curated', ['bravo', 'alfa', 'charlie']];
        yield 'recent' => ['recent', ['charlie', 'bravo', 'alfa']];
        yield 'price_asc' => ['price_asc', ['charlie', 'alfa', 'bravo']];
        yield 'price_desc' => ['price_desc', ['bravo', 'alfa', 'charlie']];
        yield 'name' => ['name', ['alfa', 'bravo', 'charlie']];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('sorts')]
    public function test_each_sort_orders_the_grid(string $sort, array $expected): void
    {
        $this->seedForSorting();

        $this->assertSame($expected, $this->slugs(['sort' => $sort]));
    }

    /** SP-3 · un `sort` que no existe no se interpreta: cae al curado. */
    public function test_an_invalid_sort_falls_back_to_curated(): void
    {
        $this->seedForSorting();

        foreach (['price_asc; DROP TABLE products', 'relevance', 'PRICE_ASC'] as $sort) {
            $this->visit(['sort' => $sort])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('filters.sort', null));
            $this->assertSame(['bravo', 'alfa', 'charlie'], $this->slugs(['sort' => $sort]));
        }
    }

    /**
     * El cursor de cada orden recorre el conjunto entero sin repetir ni
     * saltarse nada, **con empates**: trece productos del mismo precio y del
     * mismo nombre, que solo el `id` distingue.
     */
    /**
     * @return iterable<string, array{string}>
     */
    public static function sortNames(): iterable
    {
        foreach (self::sorts() as $name => [$sort]) {
            yield $name => [$sort];
        }
    }

    #[DataProvider('sortNames')]
    public function test_each_sort_paginates_through_ties(string $sort): void
    {
        $this->withinOrganization($this->organization, function (): void {
            $products = [];
            foreach (range(1, CatalogCursor::PER_PAGE + 2) as $n) {
                $products[] = Product::factory()->published()->create(['name' => 'Igual', 'slug' => 'igual-'.$n, 'price_cents' => 30_000]);
            }
            $this->attach($this->catalog, $products);
            DB::table('catalog_product')->where('catalog_id', $this->catalog->getKey())->update(['created_at' => '2026-09-01 10:00:00']);
        });

        $slugs = $this->allSlugs(['sort' => $sort]);

        $this->assertCount(CatalogCursor::PER_PAGE + 2, array_unique($slugs));
    }

    /** SP-9 · el cursor de otro orden no continúa nada: primera página. */
    public function test_the_cursor_of_another_sort_restarts_at_the_first_page(): void
    {
        $this->seedMany(CatalogCursor::PER_PAGE + 1);

        $curated = $this->props($this->visit([]))['products']['nextCursor'];

        $this->visit(['sort' => 'price_asc', 'cursor' => $curated])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', CatalogCursor::PER_PAGE));
    }

    /** El cursor con sus filtros sigue dentro del conjunto filtrado. */
    public function test_the_cursor_continues_inside_the_filtered_set(): void
    {
        $rows = [];
        foreach (range(1, CatalogCursor::PER_PAGE + 1) as $n) {
            $rows[] = ['Taladro '.$n, [], 'Taladros'];
            $rows[] = ['Sierra '.$n, [], 'Sierras'];
        }
        $this->stock($rows);

        $slugs = $this->allSlugs(['category' => 'taladros', 'sort' => 'price_desc']);

        $this->assertCount(CatalogCursor::PER_PAGE + 1, $slugs);
        $this->assertSame([], array_filter($slugs, fn (string $slug): bool => str_starts_with($slug, 'sierra')));
    }

    // ------------------------------------------------------------------
    // La URL (RB-6, RB-7)
    // ------------------------------------------------------------------

    /**
     * RB-6 · la URL tal como la escribe la página —con `tags[]`— la abre
     * otra persona sin sesión y ve exactamente el mismo conjunto.
     */
    public function test_the_url_with_filters_reproduces_the_same_set(): void
    {
        $this->stock([['Taladro rojo', [], 'Herramientas', ['oferta']], ['Taladro azul', [], 'Herramientas', ['nuevo']], ['Taladro verde', [], 'Herramientas'], ['Sierra', [], 'Herramientas', ['oferta']]]);
        $url = '/c/'.$this->catalog->slug.'?q=taladro&category=herramientas&tags[]=oferta&tags[]=nuevo&sort=price_asc';

        $this->withoutOrganization();
        $first = $this->props($this->get($url)->assertOk());
        $this->withoutOrganization();
        $second = $this->props($this->get($url)->assertOk());

        $this->assertSame(['taladro-rojo', 'taladro-azul'], array_column($first['products']['data'], 'slug'));
        $this->assertSame($first['products'], $second['products']);
        $this->assertSame(['q' => 'taladro', 'category' => 'herramientas', 'tags' => ['oferta', 'nuevo'], 'sort' => 'price_asc', 'ignored' => false], $first['filters']);
    }

    /**
     * RB-7 · la recarga parcial de un filtro trae la parrilla y los filtros, y
     * ni el catálogo ni las facetas, que no dependen de lo filtrado.
     */
    public function test_a_filter_change_is_a_partial_reload_of_products_and_filters(): void
    {
        $this->stock([['Taladro', [], 'Herramientas'], ['Sierra', [], 'Cortes']]);
        $this->withoutOrganization();

        $response = $this->get('/c/'.$this->catalog->slug.'?category=cortes', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
            'X-Inertia-Partial-Component' => 'catalog/CatalogShow',
            'X-Inertia-Partial-Data' => 'products,filters',
        ])->assertOk();

        $props = $response->json('props');
        $this->assertSame(['products', 'filters'], array_values(array_intersect(array_keys($props), ['catalog', 'products', 'filters', 'facets'])));
        $this->assertSame(['sierra'], array_column($props['products']['data'], 'slug'));
    }

    // ------------------------------------------------------------------
    // Lo que no existe (RB-9) y lo que es de otra tarea (T-084)
    // ------------------------------------------------------------------

    /**
     * RB-9 · no hay página de categoría ni de etiqueta fuera de un catálogo:
     * las únicas rutas que las nombran son las del backoffice del negocio.
     */
    public function test_there_is_no_category_or_tag_route_outside_a_catalog(): void
    {
        $public = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => preg_match('/categor|etiqueta|tag/i', $uri) === 1)
            ->reject(fn (string $uri): bool => str_starts_with($uri, 'backoffice/'))
            ->values()
            ->all();

        $this->assertSame([], $public, 'Rutas de taxonomía fuera del backoffice: '.implode(', ', $public));
    }

    /**
     * RB-5 de `REQ-CAT-002` y RB-8 de `REQ-CAT-006`: `search_performed` y
     * `product_list_filtered` son de `T-084`. El día que exista el bus, esto se
     * pone rojo para que la búsqueda y los filtros los emitan.
     */
    public function test_the_search_and_filter_telemetry_is_still_pending(): void
    {
        $this->assertFalse(
            enum_exists('App\\Domain\\Telemetry\\TelemetryEventName') || class_exists('App\\Domain\\Telemetry\\TelemetryEventName'),
            'El bus de telemetría ya existe (T-084): emite search_performed desde la búsqueda del catálogo y '
            .'product_list_filtered desde los filtros, con el catalog_id, y borra este caso.',
        );
    }

    // ------------------------------------------------------------------

    /**
     * Siembra en el catálogo del test, en ese orden curado.
     *
     * @param  list<array{0: string, 1?: array<string, mixed>, 2?: string, 3?: list<string>}>  $rows  nombre, atributos, categoría, etiquetas
     */
    private function stock(array $rows): void
    {
        $this->withinOrganization($this->organization, function () use ($rows): void {
            $products = [];

            foreach ($rows as $row) {
                $category = isset($row[2])
                    ? Category::query()->where('name', $row[2])->first() ?? Category::factory()->named($row[2])->create()
                    : null;

                $product = $this->product($row[0], $category, $row[1] ?? []);
                $product->tags()->sync(array_map(
                    fn (string $tag): int => (int) (Tag::query()->where('name', $tag)->value('id') ?? Tag::factory()->named($tag)->create()->getKey()),
                    $row[3] ?? [],
                ));

                $products[] = $product;
            }

            $this->attach($this->catalog, $products);
        });
    }

    private function seedMany(int $count): void
    {
        $this->stock(array_map(fn (int $n): array => ['Producto '.$n], range(1, $count)));
    }

    /**
     * Tres productos donde cada orden da una secuencia distinta.
     */
    private function seedForSorting(): void
    {
        $this->stock([
            ['Bravo', ['price_cents' => 30_000]],
            ['Alfa', ['price_cents' => 20_000]],
            ['Charlie', ['price_cents' => 10_000]],
        ]);

        foreach (['bravo' => '2026-09-02', 'alfa' => '2026-09-01', 'charlie' => '2026-09-03'] as $slug => $date) {
            DB::table('catalog_product')
                ->where('catalog_id', $this->catalog->getKey())
                ->where('product_id', $this->withinOrganization($this->organization, fn () => Product::query()->where('slug', $slug)->value('id')))
                ->update(['created_at' => $date.' 10:00:00']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(string $name, ?Category $category = null, array $attributes = []): Product
    {
        return Product::factory()->published()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            // Solo cifras: el SKU aleatorio de la factory son diez letras y
            // cifras, y de vez en cuando trae «TV» y la búsqueda por «tv» lo
            // encuentra (falló así en la ronda paralela de `T-066`).
            'sku' => 'SKU-'.random_int(1_000_000, 9_999_999),
            'category_id' => $category?->getKey(),
            ...$attributes,
        ]);
    }

    /**
     * @param  list<Product>  $products  en el orden en que quedan, detrás de los que ya hay
     */
    private function attach(Catalog $catalog, array $products): void
    {
        $offset = (int) DB::table('catalog_product')->where('catalog_id', $catalog->getKey())->max('position');

        foreach ($products as $index => $product) {
            $catalog->products()->attach($product, ['position' => $offset + $index + 1]);
        }
    }

    private function subscribe(Organization $organization): void
    {
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan(Plan::factory()->create())->create());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visit(array $query): TestResponse
    {
        $this->withoutOrganization();

        return $this->get('/c/'.$this->catalog->slug.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * Los slugs del primer lote.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function slugs(array $query): array
    {
        return array_column($this->props($this->visit($query)->assertOk())['products']['data'], 'slug');
    }

    /**
     * Los slugs de todos los lotes, siguiendo el cursor como «Cargar más»:
     * con los mismos filtros en la URL y el cursor en la petición.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function allSlugs(array $query): array
    {
        $slugs = [];
        $cursor = null;

        do {
            $page = $this->props($this->visit($cursor === null ? $query : [...$query, 'cursor' => $cursor])->assertOk())['products'];
            $slugs = [...$slugs, ...array_column($page['data'], 'slug')];
            $cursor = $page['nextCursor'];
            $this->assertLessThan(20, count($slugs) / CatalogCursor::PER_PAGE, 'El cursor no termina.');
        } while ($cursor !== null);

        return $slugs;
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $props = [];
        $response->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    }
}
