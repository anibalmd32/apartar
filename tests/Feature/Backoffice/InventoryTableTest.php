<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El inventario como tabla server-driven (`REQ-BOF-045`, `REQ-BOF-020` RB-9).
 */
final class InventoryTableTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withoutOrganization();
    }

    /**
     * El motivo de la tarea: con treinta productos llegan veinticinco, y la
     * `meta` dice cuántos hay y cuántas páginas.
     */
    public function test_it_paginates_in_the_server_instead_of_bringing_the_whole_table(): void
    {
        $this->seedProducts($this->organization, 30);

        $this->visit('/backoffice/productos')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 25)
            ->where('meta.page', 1)
            ->where('meta.perPage', 25)
            ->where('meta.total', 30)
            ->where('meta.lastPage', 2)
            ->where('meta.hasMore', true)
            ->where('meta.sort', '-created'));

        $this->visit('/backoffice/productos?page=2')->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 5));
    }

    /**
     * RB-4 y SP-1 · el orden de una columna de la lista blanca se aplica, en
     * los dos sentidos; cualquier otro texto cae al defecto sin error.
     *
     * @return iterable<string, array{string}>
     */
    public static function unknownSorts(): iterable
    {
        yield 'columna inexistente' => ['password'];
        yield 'nombre de columna real' => ['price_cents'];
        yield 'inyección' => ['name;drop table products'];
        yield 'doble signo' => ['--name'];
        yield 'mayúsculas' => ['Name'];
    }

    public function test_a_whitelisted_sort_is_applied_both_ways(): void
    {
        $this->seedProducts($this->organization, 3, fn (int $n): array => ['name' => ['Bravo', 'Alfa', 'Charlie'][$n]]);

        $this->visit('/backoffice/productos?sort=name')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.name', 'Alfa')
            ->where('meta.sort', 'name'));

        $this->visit('/backoffice/productos?sort=-name')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.name', 'Charlie')
            ->where('meta.sort', '-name'));
    }

    #[DataProvider('unknownSorts')]
    public function test_an_unknown_sort_falls_back_to_the_default(string $sort): void
    {
        $this->seedProducts($this->organization, 2, fn (int $n): array => ['name' => ['Primero', 'Segundo'][$n]]);

        $this->visit('/backoffice/productos?sort='.urlencode($sort))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meta.sort', '-created')
            ->where('rows.0.name', 'Segundo'));
    }

    /**
     * RB-5 y SP-2 · `per_page` fuera de {25, 50, 100} cae a 25.
     */
    public function test_per_page_outside_the_list_falls_back_to_25(): void
    {
        $this->seedProducts($this->organization, 60);

        $this->visit('/backoffice/productos?per_page=50')->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 50)->where('meta.perPage', 50));

        foreach (['5000', '7', '-1', 'abc', ''] as $perPage) {
            $this->visit('/backoffice/productos?per_page='.$perPage)->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 25)->where('meta.perPage', 25));
        }
    }

    /**
     * SP-3 · una página desbordada vuelve a la última con datos, sin `404`; y
     * una página que no es un número es la primera.
     */
    public function test_an_overflowing_page_lands_on_the_last_page_with_data(): void
    {
        $this->seedProducts($this->organization, 30);

        $this->visit('/backoffice/productos?page=99')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 5)
            ->where('meta.page', 2));

        $this->visit('/backoffice/productos?page=abc')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('meta.page', 1));
    }

    /**
     * RB-9 · los cinco filtros, cada uno sobre lo que existe, y combinables.
     */
    public function test_the_five_filters_narrow_the_listing(): void
    {
        [$tools, $sale] = $this->withinOrganization($this->organization, function (): array {
            $tools = Category::factory()->create();
            $sale = Tag::factory()->create();

            $drill = Product::factory()->published()->inCategory($tools)->create(['name' => 'Taladro', 'sku' => 'TAL-1', 'stock' => 2]);
            $drill->tags()->attach($sale);
            Product::factory()->inCategory($tools)->create(['name' => 'Sierra', 'stock' => 40]);
            Product::factory()->published()->create(['name' => 'Pintura', 'stock' => 1]);

            return [$tools, $sale];
        });

        $this->assertNames('/backoffice/productos?status=published', ['Pintura', 'Taladro']);
        $this->assertNames('/backoffice/productos?category='.$tools->getKey(), ['Sierra', 'Taladro']);
        $this->assertNames('/backoffice/productos?tag='.$sale->getKey(), ['Taladro']);
        $this->assertNames('/backoffice/productos?low_stock=1', ['Pintura', 'Taladro']);
        $this->assertNames('/backoffice/productos?q=tal-', ['Taladro']);
        $this->assertNames('/backoffice/productos?status=published&category='.$tools->getKey().'&low_stock=1', ['Taladro']);

        $this->visit('/backoffice/productos?category='.$tools->getKey().'&tag='.$sale->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('meta.filters.category', (string) $tools->getKey())
                ->where('meta.filters.tag', (string) $sale->getKey())
                ->where('meta.ignoredFilters', []));
    }

    /**
     * SP-4 · un valor que no se puede usar se ignora **y se dice**: el resto
     * de la tabla sale, y la clave del filtro viaja en `ignoredFilters`. La
     * categoría del vecino no existe aquí, igual que una borrada.
     */
    public function test_an_invalid_filter_is_ignored_and_reported(): void
    {
        $this->seedProducts($this->organization, 2);
        $theirs = $this->withinOrganization($this->neighbour, fn (): Category => Category::factory()->create());

        $this->visit('/backoffice/productos?status=inventado&category='.$theirs->getKey().'&tag=999999&low_stock=si')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('meta.filters.status', null)
                ->where('meta.filters.category', null)
                ->where('meta.ignoredFilters', ['status', 'category', 'tag', 'low_stock']));
    }

    /**
     * SP-8 · **la misma URL, con los mismos filtros, en dos negocios**: cada
     * equipo ve lo suyo. El estado viaja en la query string y el global scope
     * sigue siendo el que filtra.
     */
    public function test_a_shared_url_shows_each_organization_its_own_data(): void
    {
        $this->seedProducts($this->organization, 3, fn (int $n): array => ['name' => 'Nuestro '.$n, 'stock' => 1]);
        $this->seedProducts($this->neighbour, 4, fn (int $n): array => ['name' => 'Del vecino '.$n, 'stock' => 1]);

        $url = '/backoffice/productos?low_stock=1&sort=name&per_page=50';

        $ours = $this->names($this->visit($url, $this->organization));
        $theirs = $this->names($this->visit($url, $this->neighbour));

        $this->assertSame(['Nuestro 0', 'Nuestro 1', 'Nuestro 2'], $ours);
        $this->assertSame(['Del vecino 0', 'Del vecino 1', 'Del vecino 2', 'Del vecino 3'], $theirs);
    }

    /**
     * RB-2 · la recarga parcial pide **solo `rows` y `meta`**, y las opciones
     * de los filtros no se vuelven a calcular.
     */
    public function test_a_partial_reload_brings_only_rows_and_meta(): void
    {
        $this->seedProducts($this->organization, 3);
        $owner = $this->owner($this->organization);

        $version = $this->actingAs($owner)->get('/backoffice/productos')->viewData('page')['version'];

        $this->withoutOrganization();

        $response = $this->actingAs($owner)->get('/backoffice/productos?page=1&sort=name', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'backoffice/products/Index',
            'X-Inertia-Partial-Data' => 'rows,meta',
        ]);

        $props = $response->assertOk()->json('props');

        $this->assertArrayHasKey('rows', $props);
        $this->assertArrayHasKey('meta', $props);
        $this->assertArrayNotHasKey('categories', $props);
        $this->assertArrayNotHasKey('tags', $props);
        $this->assertArrayNotHasKey('statuses', $props);
    }

    /**
     * SP-7 de `REQ-BOF-020` · la fila lleva la marca de última modificación
     * con su autor.
     */
    public function test_each_row_carries_its_last_edit_and_author(): void
    {
        $owner = $this->owner($this->organization);
        $this->withinOrganization($this->organization, fn () => Product::factory()->create()->editedBy($owner)->forceFill(['name' => 'Editado'])->save());

        $this->visit('/backoffice/productos', user: $owner)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.updatedBy', $owner->name)
            ->where('rows.0.updatedAt', fn (mixed $at): bool => is_string($at) && $at !== ''));
    }

    /**
     * Lo que cuesta la página: las consultas no crecen con las filas, y
     * ninguna es por producto.
     */
    public function test_the_listing_does_not_query_per_row(): void
    {
        $this->seedProducts($this->organization, 5);
        $few = $this->queriesFor('/backoffice/productos?sort=price&low_stock=1');

        $this->seedProducts($this->organization, 20);
        $many = $this->queriesFor('/backoffice/productos?sort=price&low_stock=1');

        $this->assertSame($few, $many);
    }

    // ------------------------------------------------------------------

    /**
     * @param  (Closure(int): array<string, mixed>)|null  $attributes
     */
    private function seedProducts(Organization $organization, int $count, ?Closure $attributes = null): void
    {
        $this->withinOrganization($organization, function () use ($count, $attributes): void {
            for ($n = 0; $n < $count; $n++) {
                Product::factory()->create($attributes === null ? ['stock' => 1] : $attributes($n));
            }
        });
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visit(string $url, ?Organization $organization = null, ?User $user = null): TestResponse
    {
        $user ??= $this->owner($organization ?? $this->organization);

        $this->withoutOrganization();

        return $this->actingAs($user)->get($url);
    }

    private function owner(Organization $organization): User
    {
        return $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        $names = [];
        $response->assertInertia(function (AssertableInertia $page) use (&$names): void {
            $names = array_column($page->toArray()['props']['rows'], 'name');
        });

        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertNames(string $url, array $expected): void
    {
        $this->assertSame($expected, $this->names($this->visit($url)), $url);
    }

    private function queriesFor(string $url): int
    {
        $owner = $this->owner($this->organization);
        $this->visit($url, user: $owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit($url, user: $owner)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
