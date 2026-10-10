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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Qué productos hay en un catálogo y en qué orden (`REQ-CAT-023`).
 *
 * Cierra el pivote `catalog_product` que `T-024` creó y que hasta ahora no
 * escribía nadie. Dos casos de aquí valen por todo lo demás:
 *
 *  - **el `created_at` sobrevive al reordenamiento** (RB-3), que es la regla
 *    cuyo síntoma no aparece en esta tarea sino en H6, cuando el feed anuncie
 *    como novedades productos de hace meses;
 *  - **el producto del negocio de al lado se rechaza** (RB-1, SP-1), que es lo
 *    que sostiene que el pivote pueda seguir sin `organization_id`.
 */
final class SyncCatalogProductsActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
        // `T-067` · crear, publicar y componer preguntan al plan.
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    // ------------------------------------------------------------------
    // El orden (RB-2, SP-2)
    // ------------------------------------------------------------------

    /**
     * RB-2 · las posiciones quedan contiguas desde 1, **recalculadas en el
     * servidor**. La pantalla no manda `position`: manda la lista en su orden.
     */
    public function test_it_writes_contiguous_positions_from_one(): void
    {
        $catalog = Catalog::factory()->create();
        $products = Product::factory()->count(3)->create();

        $this->sync()->handle($catalog, $this->idsOf($products), $this->actor);

        $this->assertSame([1, 2, 3], $this->positionsIn($catalog));
        $this->assertSame($this->idsOf($products), $this->orderedIdsIn($catalog));
    }

    /**
     * Y siguen contiguas después de quitar uno de en medio: un hueco no se ve
     * en la pantalla —la lista se pinta ordenada igual— pero el siguiente
     * arrastre calcularía sobre una numeración que no coincide con lo que la
     * persona está viendo.
     */
    public function test_removing_one_in_the_middle_leaves_no_gap(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second, $third] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second, $third], $this->actor);
        $this->sync()->handle($catalog, [$first, $third], $this->actor);

        $this->assertSame([1, 2], $this->positionsIn($catalog));
        $this->assertSame([$first, $third], $this->orderedIdsIn($catalog));
    }

    /**
     * SP-2 · identificadores repetidos se deduplican por su **primera
     * aparición**. Es un arrastre mal serializado o un doble clic en «añadir»,
     * no una intención — y la PK compuesta lo rechazaría con un error de base
     * de datos en lugar de con la lista que la persona quería (RB-4).
     */
    public function test_duplicate_ids_are_deduplicated_keeping_the_first_one(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second, $third] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second, $first, $third, $second], $this->actor);

        $this->assertSame([$first, $second, $third], $this->orderedIdsIn($catalog));
        $this->assertSame([1, 2, 3], $this->positionsIn($catalog));
    }

    // ------------------------------------------------------------------
    // `created_at`, que es el campo que no se puede tocar (RB-3)
    // ------------------------------------------------------------------

    /**
     * **RB-3 · reordenar no convierte un producto viejo en novedad.**
     *
     * El `created_at` del pivote registra cuándo entró el producto **a este
     * catálogo**, y es lo que ordena el feed multi-negocio y decide qué es
     * novedad (`REQ-ACC-003` RB-2 y RB-4). Este test no pasaría con un
     * guardado que desatara el pivote y volviera a atarlo —la forma que parece
     * más limpia—: borraría y reinsertaría las filas que no habían cambiado, y
     * con ellas la fecha.
     *
     * El síntoma no aparecería aquí. Aparecería en H6, cuando a un cliente le
     * anuncien como novedades doce productos que llevaban meses en ese
     * catálogo porque el negocio reordenó su escaparate, y nadie relacionará
     * una cosa con la otra.
     */
    public function test_reordering_does_not_touch_the_pivot_created_at(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second, $third] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second, $third], $this->actor);

        $before = $this->createdAtIn($catalog);

        // Un salto grande y explícito: si la fecha se reescribiera, la
        // diferencia no cabría en el margen de un segundo.
        $this->travel(30)->days();

        $this->sync()->handle($catalog, [$third, $first, $second], $this->actor);

        $this->assertSame($before, $this->createdAtIn($catalog));
        $this->assertSame([$third, $first, $second], $this->orderedIdsIn($catalog));
        $this->assertSame([1, 2, 3], $this->positionsIn($catalog));
    }

    /**
     * Y el que **entra hoy** estrena fecha, que es la otra mitad de RB-3: si
     * todas se conservaran, un producto nuevo nunca sería novedad.
     */
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

    /**
     * Quitar un producto y volver a ponerlo **sí** reinicia su fecha: volvió a
     * entrar, y para el feed eso es exactamente lo que pasó.
     */
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

    // ------------------------------------------------------------------
    // La pertenencia al tenant (RB-1, SP-1)
    // ------------------------------------------------------------------

    /**
     * **RB-1 y SP-1 · el producto del negocio de al lado se rechaza**, y este
     * es el test que sostiene que `catalog_product` pueda seguir sin
     * `organization_id`: la spec pone esa guarda en la `Action`
     * (`REQ-ORG-002` RB-7) y esto es lo que comprueba que está puesta.
     */
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

        // SP-1 · **sin aplicar nada**: ni siquiera el que sí era válido.
        $this->assertSame(0, $catalog->products()->count());
        $this->assertSame(0, DB::table('catalog_product')->count());
    }

    /**
     * Un identificador que no existe da **la misma respuesta**, y es
     * deliberado: bajo el *global scope* «no existe» y «es de otro negocio»
     * son el mismo hecho, y distinguirlos confirmaría que la fila del vecino
     * existe (`REQ-ORG-002` RB-6).
     */
    public function test_an_unknown_id_is_refused_the_same_way(): void
    {
        $catalog = Catalog::factory()->create();

        $this->expectException(CrossTenantRelationException::class);

        $this->sync()->handle($catalog, [999_999], $this->actor);
    }

    /**
     * SP-1 · el rechazo **no aplica nada**, ni siquiera lo que ya estaba
     * guardado: el catálogo queda exactamente como estaba, no a medias.
     */
    public function test_a_refused_save_leaves_the_previous_composition_intact(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        try {
            $this->sync()->handle($catalog, [$second, 999_999], $this->actor);
        } catch (CrossTenantRelationException) {
            // Esperado: lo que importa es lo que quedó en la tabla.
        }

        $this->assertSame([$first, $second], $this->orderedIdsIn($catalog));
        $this->assertSame([1, 2], $this->positionsIn($catalog));
    }

    // ------------------------------------------------------------------
    // Estados del producto (RB-5, SP-1)
    // ------------------------------------------------------------------

    /**
     * **RB-5 · los `draft` entran**, y es el caso de uso: preparar una campaña
     * entera antes de lanzarla. Lo que no se renderiza lo decide la consulta
     * pública de `T-033`, no esta acción.
     */
    public function test_draft_products_are_accepted(): void
    {
        $catalog = Catalog::factory()->create();
        $draft = Product::factory()->create();

        $this->sync()->handle($catalog, [(int) $draft->getKey()], $this->actor);

        $this->assertSame(ProductStatus::Draft, $draft->refresh()->status);
        $this->assertSame(1, $catalog->products()->count());
    }

    /**
     * SP-1 · **añadir** un archivado se rechaza. Está retirado de la venta, y
     * meterlo en un escaparate sería seguir componiendo con lo que se retiró.
     */
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

    /**
     * **Pero el que ya estaba dentro se queda** (`REQ-BOF-024` SP-7), y esta
     * es la otra mitad de la misma regla: archivar conserva la relación con el
     * catálogo (`REQ-CAT-007` RB-7), así que rechazar siempre haría imposible
     * guardar el orden de un catálogo que tenga uno archivado dentro — y el
     * negocio se quedaría sin poder tocar su escaparate por una foto que
     * retiró.
     */
    public function test_an_archived_product_that_was_already_in_can_stay(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        Product::query()->whereKey($first)->update(['status' => ProductStatus::Archived]);

        $this->sync()->handle($catalog, [$second, $first], $this->actor);

        $this->assertSame([$second, $first], $this->orderedIdsIn($catalog));
    }

    /**
     * Y quitarlo sigue siendo posible: eso es una decisión explícita del
     * negocio, no un efecto colateral de haberlo archivado.
     */
    public function test_an_archived_product_may_still_be_removed(): void
    {
        $catalog = Catalog::factory()->create();
        [$first, $second] = $this->threeProducts();

        $this->sync()->handle($catalog, [$first, $second], $this->actor);

        Product::query()->whereKey($first)->update(['status' => ProductStatus::Archived]);

        $this->sync()->handle($catalog, [$second], $this->actor);

        $this->assertSame([$second], $this->orderedIdsIn($catalog));
    }

    // ------------------------------------------------------------------
    // Vaciar (SP-7) y compartir (SP-8)
    // ------------------------------------------------------------------

    /**
     * **SP-7 · vaciar un catálogo publicado es `422`.** Su enlace ya circula
     * por WhatsApp, y quien lo abra no vería un error: vería un negocio que no
     * vende. Es la misma familia que `REQ-CAT-022` RB-4.
     */
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

    /**
     * SP-7 · **en borrador sí se permite**: un borrador vacío es un catálogo a
     * medio preparar, que es exactamente lo que RB-5 quiere que se pueda
     * hacer.
     */
    public function test_emptying_a_draft_catalog_is_allowed(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->create();

        $this->sync()->handle($catalog, [(int) $product->getKey()], $this->actor);
        $this->sync()->handle($catalog, [], $this->actor);

        $this->assertSame(0, $catalog->products()->count());
    }

    /**
     * **SP-8 · el mismo producto en varios catálogos del mismo negocio.** Es
     * la relación N:M de RB-1, y lo que hace que un producto estrella pueda
     * estar en la campaña de septiembre y en la de navidad sin duplicarse.
     * Cada catálogo lleva su propia posición y su propia fecha de entrada.
     */
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

    // ------------------------------------------------------------------
    // Permisos y lo que esta acción no hace
    // ------------------------------------------------------------------

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

    /**
     * **Componer no se audita**, y no es un olvido: `REQ-CAT-023` no lo pide y
     * la lista de eventos de `REQ-BOF-005` RB-1 es cerrada. Lo que sí se
     * audita del catálogo es publicarlo y despublicarlo (`REQ-CAT-022` RB-10),
     * que es de `T-032`. Añadir aquí un evento la haría crecer sin tocar la
     * spec.
     */
    public function test_composing_writes_no_audit_entry(): void
    {
        $catalog = Catalog::factory()->create();
        $product = Product::factory()->create();

        $this->sync()->handle($catalog, [(int) $product->getKey()], $this->actor);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * SP-4 · el catálogo queda marcado como modificado, que es de donde sale
     * la marca de última modificación que el backoffice enseña cuando dos
     * personas componen el mismo. Sin esto, la marca diría la fecha del último
     * cambio de nombre.
     */
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
     * Tres productos ya creados, por identificador.
     *
     * @return list<int>
     */
    private function threeProducts(): array
    {
        return $this->idsOf(Product::factory()->count(3)->create());
    }

    /**
     * Las posiciones del catálogo, ordenadas.
     *
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
     * Los productos del catálogo en su orden curado.
     *
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
     * La posición de un producto concreto dentro de un catálogo.
     *
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
     * La fecha de entrada de cada producto, tal como está en la tabla.
     *
     * Se lee con `DB::table()` y no por la relación **a propósito**: lo que
     * este test vigila es la columna, y leerla por el modelo pasaría por el
     * cast y por la relación, que son dos capas que podrían normalizar la
     * diferencia que aquí importa.
     *
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
