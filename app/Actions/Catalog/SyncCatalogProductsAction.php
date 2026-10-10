<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CatalogProductOrder;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\CatalogProductLimitReachedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Qué productos entran en un catálogo y en qué orden se ven
 * (`REQ-CAT-023`).
 *
 * Cierra el pivote `catalog_product` que existe desde `T-024` y que hasta hoy
 * no escribía nadie. La aritmética del orden y la conservación del
 * `created_at` viven en `CatalogProductOrder`; lo que queda aquí es lo que
 * decide **qué identificadores llegan hasta ella**, que es donde están las
 * tres reglas que se pueden equivocar.
 *
 * ## La pertenencia al tenant se valida aquí, y por eso el pivote no lleva
 * columna
 *
 * `catalog_product` no tiene `organization_id`, declarado así en `T-024`: la
 * tabla se lee desde la capa de plataforma —el feed multi-negocio cruza
 * organizaciones a propósito—, así que la columna no filtraría nada y quedaría
 * como un segundo censo de a quién pertenece la fila, capaz de discrepar de
 * `catalog_id`. Lo que impide mezclar el producto de un negocio con el
 * catálogo de otro es **esta validación**, que es donde `REQ-ORG-002` RB-7 la
 * pone (RB-1, SP-1).
 *
 * Y se resuelve por consulta del tenant, no comparando `organization_id` a
 * mano: bajo el *global scope* el producto ajeno **no existe**, así que el
 * identificador simplemente no resuelve. Es la misma forma que `T-027` usó
 * para las etiquetas, y tiene la misma consecuencia deliberada: «no existe» y
 * «es de otro» dan la misma respuesta, porque distinguirlas confirmaría que la
 * fila del vecino existe (`REQ-ORG-002` RB-6).
 *
 * ## Archivado: se niega al entrar, se tolera al quedarse
 *
 * SP-1 rechaza añadir un producto archivado. `REQ-BOF-024` SP-7 dice que un
 * producto del catálogo que se archiva **sigue en él**, no se renderiza y el
 * backoffice lo marca «no visible». Las dos son ciertas a la vez solo con esta
 * lectura, y la alternativa rompe algo: rechazar siempre haría imposible
 * guardar el orden de un catálogo que tenga uno archivado dentro, y admitir
 * siempre convertiría «archivar» en una forma silenciosa de seguir
 * componiendo escaparates con lo retirado.
 *
 * Quitarlo de la lista sigue siendo posible —eso es una decisión explícita del
 * negocio—; lo que no se puede es **meterlo**.
 *
 * ## Los `draft` sí entran
 *
 * RB-5, y es el caso de uso: preparar una campaña entera antes de lanzarla.
 * Lo que no se renderiza lo decide la consulta pública (`T-033`), no esta
 * acción — y esa separación es la que permite que el negocio componga con
 * calma.
 *
 * ## Lo que no está y tiene dueño
 *
 * **`max_products_per_catalog` (RB-6, SP-3) no se comprueba aquí**, por lo
 * mismo que en las siete tareas anteriores: las llamadas de límite las inserta
 * `T-067`. Este tope es además **el distinto de los doce** —no pasa por
 * `LimitEnforcer::assertCanCreate()` porque no tiene `UsageMetric`: se cuenta
 * por catálogo y los contadores son por organización—, así que quien busque el
 * patrón de los otros once dejará este sin hacer y sin síntoma. El punto donde
 * entra está señalado abajo, con el número de filas ya contado.
 */
final class SyncCatalogProductsAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CatalogProductOrder $order,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * Reescribe la composición del catálogo en una sola transacción.
     *
     * Todo o nada (SP-1): un identificador que no resuelve deja el catálogo
     * **exactamente como estaba**, sin haber movido ninguna posición. Aplicar
     * lo que sí resuelve dejaría al negocio con un escaparate a medio guardar
     * y un mensaje de error encima, sin saber qué mitad quedó.
     *
     * @param  list<int>  $productIds  identificadores en el orden pedido
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws CrossTenantRelationException si algún identificador no es de esta organización
     * @throws ValidationException si se añade un archivado o se vacía un catálogo publicado
     * @throws CatalogProductLimitReachedException si el catálogo crece por encima del tope del plan
     */
    public function handle(Catalog $catalog, array $productIds, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        DB::transaction(function () use ($catalog, $productIds, $actor): void {
            // La fila del catálogo, cogida antes de contar: es lo que
            // serializa dos composiciones del mismo catálogo, que de otro modo
            // contarían las dos lo de antes y pasarían las dos el tope.
            Catalog::query()->whereKey($catalog->getKey())->lockForUpdate()->firstOrFail();

            $requested = $this->order->deduplicate($productIds);

            $this->assertEveryProductIsAddable($catalog, $requested);
            $this->assertNotEmptyingAPublishedCatalog($catalog, $requested);

            // `REQ-SUB-004` RB-3 · el tope de `max_products_per_catalog`
            // (`REQ-CAT-023` RB-6, SP-3, `T-067`): `count($requested)` es el
            // total del pivote tras el guardado, **incluidos los `draft`**,
            // que es como RB-6 lo cuenta. Solo se rechaza lo que entra: un
            // catálogo por encima del techo tras bajar de plan se reordena y
            // se vacía, pero no recibe productos nuevos.
            $current = $catalog->products()->pluck('products.id')->map(fn (mixed $id): int => (int) $id)->all();

            $this->limits->assertCatalogHolds(
                count($current),
                count($requested),
                addsNew: array_diff($requested, $current) !== [],
            );

            $this->order->apply($catalog, $requested);

            // SP-4 · la marca de última modificación que el backoffice enseña
            // cuando dos personas componen el mismo catálogo. La composición
            // es del catálogo aunque viva en otra tabla, así que cambiarla lo
            // cambia a él; sin esto, la marca diría la fecha del último cambio
            // de nombre. Y el autor es quien compuso, por lo mismo
            // (`RecordsLastEditor`, `T-038`).
            $catalog->touchedBy($actor);
        });
    }

    /**
     * Cada identificador pedido es un producto de esta organización que puede
     * estar en un catálogo (RB-1, RB-5, SP-1).
     *
     * @param  list<int>  $productIds
     *
     * @throws CrossTenantRelationException si alguno no resuelve bajo el tenant
     * @throws ValidationException si se intenta **añadir** uno archivado
     */
    private function assertEveryProductIsAddable(Catalog $catalog, array $productIds): void
    {
        $alreadyIn = $catalog->products()
            ->pluck('products.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($productIds as $productId) {
            $product = Product::query()->find($productId);

            if (! $product instanceof Product) {
                // Inexistente o del negocio de al lado: la misma respuesta a
                // propósito, porque bajo el global scope son el mismo hecho.
                throw CrossTenantRelationException::between(Catalog::class, Product::class);
            }

            if ($product->status === ProductStatus::Archived && ! in_array($productId, $alreadyIn, true)) {
                throw ValidationException::withMessages([
                    'products' => sprintf(
                        '«%s» está archivado y no se puede añadir a un catálogo. Restáuralo primero: vuelve a '
                        .'borrador y podrás publicarlo y ponerlo donde quieras.',
                        $product->name,
                    ),
                ]);
            }
        }
    }

    /**
     * Un catálogo publicado no se puede dejar vacío (SP-7).
     *
     * Es la misma familia que `REQ-CAT-022` RB-4 —publicar exige al menos un
     * producto publicado—: un catálogo publicado y vacío es un enlace que ya
     * circula por WhatsApp y no enseña nada, y quien lo abre no ve un error,
     * ve un negocio que no vende. **En borrador sí se permite**, porque un
     * borrador vacío es un catálogo a medio preparar y eso es legítimo.
     *
     * La condición se lee de `visibility()` y no de `published_at` para que
     * siga diciendo lo mismo el día que la derivación cambie: quien decide qué
     * es «publicado» en este proyecto es ese método (`REQ-CAT-022` RB-6).
     *
     * @param  list<int>  $productIds
     *
     * @throws ValidationException si se pide vaciar un catálogo ya publicado
     */
    private function assertNotEmptyingAPublishedCatalog(Catalog $catalog, array $productIds): void
    {
        if ($productIds !== [] || $catalog->visibility() === CatalogVisibility::Draft) {
            return;
        }

        throw ValidationException::withMessages([
            'products' => sprintf(
                'No puedes dejar «%s» sin productos mientras esté publicado: su enlace ya circula y quien lo '
                .'abra no vería nada. Despublícalo primero, o deja al menos un producto.',
                $catalog->name,
            ),
        ]);
    }
}
