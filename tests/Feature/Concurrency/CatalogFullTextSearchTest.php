<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Support\CatalogCursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La búsqueda del catálogo **con el índice `FULLTEXT` de verdad**
 * (`REQ-CAT-002` RB-2, RB-4, RB-7).
 *
 * ## Por qué está en la ronda en serie sin ser de concurrencia
 *
 * InnoDB actualiza el índice `FULLTEXT` **al confirmar** la transacción: un
 * `MATCH` no ve las filas que su propia transacción acaba de insertar. Dentro
 * de `RefreshDatabase` —que envuelve cada test en una transacción que nunca se
 * confirma— toda búsqueda devuelve cero y degrada a `LIKE`, así que
 * `CatalogSearchAndFiltersTest` prueba el `LIKE` y nada más. Medido en el
 * MySQL del contenedor antes de escribir esto: la misma fila da 0 dentro de la
 * transacción y 1 después del `COMMIT`.
 *
 * Probar el índice exige confirmar, y confirmar exige lo mismo que a los tests
 * de concurrencia: sin `RefreshDatabase`, barrido a mano por prefijo en
 * `setUp` y `tearDown`, y **nadie más escribiendo en la misma base a la vez**.
 * Es la definición de este *testsuite* (ver `phpunit.xml`), aunque su carpeta
 * se llame por el primer motivo que lo trajo.
 */
final class CatalogFullTextSearchTest extends TestCase
{
    /**
     * Marca de las organizaciones y planes de este archivo, para barrerlos.
     */
    private const PREFIX = 'busqueda-fulltext-';

    private Organization $organization;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('catalog_product')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $this->purgeFixtures();

        $this->organization = $this->organizationWithPlan('propia');
        $this->catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();

        parent::tearDown();
    }

    /**
     * RB-4 · con término y sin `sort`, relevancia: el que más habla de
     * taladros va primero aunque el negocio lo pusiera segundo. Y un `sort`
     * explícito manda sobre ella.
     */
    public function test_a_search_orders_by_relevance_unless_a_sort_was_chosen(): void
    {
        $this->stock([
            ['Broca para madera', 'Sirve en cualquier taladro.'],
            ['Taladro percutor', 'Taladro de impacto: el taladro que perfora hormigón.'],
            ['Sierra circular', 'Corta tableros.'],
        ]);

        $this->assertSame(['taladro-percutor', 'broca-para-madera'], $this->slugs(['q' => 'taladro']));
        $this->assertSame(['broca-para-madera', 'taladro-percutor'], $this->slugs(['q' => 'taladro', 'sort' => 'curated']));
    }

    /** El índice busca también en la descripción, que el `LIKE` no mira. */
    public function test_the_index_finds_by_description(): void
    {
        $this->stock([['Sierra circular', 'Corta tableros de melamina.'], ['Taladro', null]]);

        $this->assertSame(['sierra-circular'], $this->slugs(['q' => 'melamina']));
    }

    /**
     * RB-7 ante el índice de verdad: `-impacto` excluiría el taladro si el
     * guion llegara como operador. Llega como separador, y se buscan las dos
     * palabras.
     */
    public function test_boolean_operators_do_not_reach_the_index(): void
    {
        $this->stock([['Taladro percutor', 'Con función de impacto.'], ['Taladro simple', 'Sin percusión.']]);

        $this->assertSame(['taladro-percutor'], $this->slugs(['q' => 'taladro -impacto']));
        $this->assertSame(['taladro-percutor'], $this->slugs(['q' => '+"taladro" (impacto*']));
    }

    /**
     * RB-2 · el índice no encuentra un trozo del medio de una palabra, y con
     * cero resultados la búsqueda degrada a `LIKE`, que sí.
     */
    public function test_it_degrades_to_like_when_the_index_finds_nothing(): void
    {
        $this->stock([['Taladro percutor', null], ['Sierra', null]]);

        $this->assertSame(['taladro-percutor'], $this->slugs(['q' => 'ladro']));
    }

    /**
     * Acotación y fuga, también con el índice: el `MATCH` se aplica sobre la
     * consulta ya acotada al catálogo y al tenant, no sobre toda la tabla.
     */
    public function test_the_index_is_bounded_to_this_catalog_and_this_organization(): void
    {
        $this->stock([['Taladro de este catálogo', null]]);
        $this->withinOrganization($this->organization, fn () => $this->attach(
            Catalog::factory()->published()->create(),
            [$this->product('Taladro de otra campaña', null)],
        ));

        $neighbour = $this->organizationWithPlan('vecina');
        $foreign = $this->withinOrganization($neighbour, fn (): Product => $this->product('Taladro de la vecina', 'Taladro taladro taladro.'));
        DB::table('catalog_product')->insert(['catalog_id' => $this->catalog->getKey(), 'product_id' => $foreign->getKey(), 'position' => 99]);

        $this->assertSame(['taladro-de-este-catalogo'], $this->slugs(['q' => 'taladro']));
    }

    /**
     * La relevancia se pagina por desplazamiento: trece coincidencias en dos
     * lotes, sin repetir ni saltarse ninguna.
     */
    public function test_relevance_paginates_by_offset_without_gaps(): void
    {
        $this->stock(array_map(fn (int $n): array => ['Tornillo '.$n, str_repeat('tornillo ', $n % 4 + 1)], range(1, CatalogCursor::PER_PAGE + 1)));

        $first = $this->props($this->visit(['q' => 'tornillo']))['products'];
        $second = $this->props($this->visit(['q' => 'tornillo', 'cursor' => $first['nextCursor']]))['products'];

        $this->assertCount(CatalogCursor::PER_PAGE, $first['data']);
        $this->assertSame(CatalogCursor::encodeOffset(CatalogCursor::PER_PAGE), $first['nextCursor']);
        $this->assertCount(1, $second['data']);
        $this->assertNull($second['nextCursor']);
        $this->assertCount(CatalogCursor::PER_PAGE + 1, array_unique([
            ...array_column($first['data'], 'slug'),
            ...array_column($second['data'], 'slug'),
        ]));
    }

    // ------------------------------------------------------------------

    /**
     * @param  list<array{string, string|null}>  $rows  nombre y descripción, en el orden curado
     */
    private function stock(array $rows): void
    {
        $this->withinOrganization($this->organization, fn () => $this->attach(
            $this->catalog,
            array_map(fn (array $row): Product => $this->product($row[0], $row[1]), $rows),
        ));
    }

    private function product(string $name, ?string $description): Product
    {
        return Product::factory()->published()->create(['name' => $name, 'slug' => Str::slug($name), 'description' => $description]);
    }

    /**
     * @param  list<Product>  $products
     */
    private function attach(Catalog $catalog, array $products): void
    {
        foreach ($products as $index => $product) {
            $catalog->products()->attach($product, ['position' => $index + 1]);
        }
    }

    private function organizationWithPlan(string $name): Organization
    {
        $organization = Organization::factory()->create(['slug' => self::PREFIX.$name]);
        $plan = Plan::factory()->create(['code' => self::PREFIX.$name]);
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($plan)->create());

        return $organization;
    }

    /**
     * @param  array<string, string>  $query
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function visit(array $query): TestResponse
    {
        $this->withoutOrganization();

        return $this->get('/c/'.$this->catalog->slug.'?'.http_build_query($query))->assertOk();
    }

    /**
     * @param  array<string, string>  $query
     * @return list<string>
     */
    private function slugs(array $query): array
    {
        return array_column($this->props($this->visit($query))['products']['data'], 'slug');
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

    /**
     * Borra lo que este archivo confirmó, en el orden que piden las claves:
     * los productos antes que sus categorías (`restrictOnDelete`), y la
     * auditoría a mano porque no lleva clave foránea (`T-016`).
     */
    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')->where('slug', 'like', self::PREFIX.'%')->pluck('id');

        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
        DB::table('plans')->where('code', 'like', self::PREFIX.'%')->delete();
    }
}
