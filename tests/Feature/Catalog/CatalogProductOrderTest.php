<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CatalogProductOrder;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El único escritor de `catalog_product` (`REQ-CAT-023` RB-2, RB-3).
 *
 * `SyncCatalogProductsActionTest` prueba las reglas que deciden **qué
 * identificadores** llegan hasta aquí. Lo que se fija en este archivo es lo
 * que la clase hace con ellos una vez que llegaron, fila por fila: que un
 * guardado que reordena, añade y quita haga exactamente **tres cosas
 * distintas** y no un borrado seguido de una reinserción.
 *
 * Esa distinción es toda la tarea: la fila que se queda conserva su
 * `created_at`, que es lo que ordena el feed multi-negocio y decide qué es
 * novedad (`REQ-ACC-003` RB-2 y RB-4).
 */
final class CatalogProductOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    /**
     * **El guardado completo, fila por fila.**
     *
     * Se parte de tres productos, se guarda una lista que reordena los dos que
     * se quedan, añade uno y quita otro. Lo que tiene que pasar:
     *
     *  - el que **se queda** conserva su `created_at` y solo cambia de sitio;
     *  - el que **entra** estrena `created_at`;
     *  - el que **sale** desaparece de la tabla.
     *
     * Las tres a la vez, porque es el caso real y porque una implementación
     * que desatara el pivote y volviera a atarlo pasaría dos de las tres.
     */
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

        // Se quedan, en su sitio nuevo y con su fecha de siempre.
        $this->assertSame(1, $after[$alsoStays]['position']);
        $this->assertSame($before[$alsoStays]['created_at'], $after[$alsoStays]['created_at']);

        $this->assertSame(3, $after[$stays]['position']);
        $this->assertSame($before[$stays]['created_at'], $after[$stays]['created_at']);

        // Entra hoy: posición 2 y fecha de hoy.
        $this->assertSame(2, $after[$joins]['position']);
        $this->assertSame(now()->startOfSecond()->toDateTimeString(), $after[$joins]['created_at']);

        // Y el que sale, sale.
        $this->assertArrayNotHasKey($leaves, $after);
        $this->assertSame(3, count($after));
    }

    /**
     * RB-2 · las posiciones son `1..n` **sin huecos**, y lo son después de
     * cualquier guardado. Un hueco no se ve en la pantalla —la lista se pinta
     * ordenada igual— pero el siguiente arrastre calcula sobre una numeración
     * que no coincide con lo que la persona está viendo.
     */
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

    /**
     * SP-2 · la deduplicación conserva **la primera aparición**, que es el
     * sitio donde la persona lo soltó. Quedarse con la última movería el
     * producto a donde se produjo el doble clic.
     */
    public function test_it_deduplicates_keeping_the_first_appearance(): void
    {
        $this->assertSame([3, 1, 2], $this->order()->deduplicate([3, 1, 3, 2, 1, 3]));
        $this->assertSame([], $this->order()->deduplicate([]));
    }

    /**
     * Guardar dos veces la misma lista no toca nada: ni las posiciones, que ya
     * eran esas, ni las fechas. Es el segundo clic de quien no vio el primero,
     * y tiene que ser inofensivo.
     */
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

    /**
     * Cada catálogo lleva su propia composición: escribir en uno no toca al
     * otro, aunque compartan producto (SP-8).
     */
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
     * Identificadores de productos recién creados en la organización activa.
     *
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
     * Las filas del pivote, por producto.
     *
     * Se leen con `DB::table()` y no por la relación **a propósito**: lo que
     * este archivo vigila son dos columnas concretas, y leerlas por el modelo
     * pasaría por la relación y por el cast, que son dos capas capaces de
     * normalizar justo la diferencia que aquí importa.
     *
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
