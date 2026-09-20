<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;

/**
 * Qué productos hay en un catálogo y en qué orden (`REQ-CAT-023` RB-2, RB-3).
 *
 * Es el **único** sitio que escribe `catalog_product`, y esa concentración es
 * lo que hace verdaderas sus dos invariantes: después de cada guardado las
 * posiciones son `1..n` sin huecos, y el `created_at` de cada fila que ya
 * estaba **sigue siendo el mismo**.
 *
 * ## `created_at` es funcional, y por eso el guardado es un diff
 *
 * RB-3: esa columna registra cuándo entró el producto **a este catálogo**, y
 * es la que ordena el feed multi-negocio y decide qué es «novedad»
 * (`REQ-ACC-003` RB-2 y RB-4). Reordenar no convierte un producto viejo en
 * novedad, así que reordenar no la toca.
 *
 * De ahí que aquí haya tres operaciones explícitas en vez de una:
 *
 *  - lo que **sigue** se actualiza, y solo `position`;
 *  - lo que **entra** se inserta, y la fecha la pone el `useCurrent()` de la
 *    columna;
 *  - lo que **sale** se borra.
 *
 * La forma que se rompe sola es la que parece más limpia: desatar el pivote
 * entero y volver a atarlo en el orden pedido. Borra y reinserta las filas que
 * no habían cambiado, y con ellas la fecha — **y el síntoma no aparece aquí**.
 * Aparece en H6, cuando el feed de un cliente le anuncie como novedades doce
 * productos que llevaban meses en ese catálogo porque el negocio reordenó su
 * escaparate, y nadie relacionará una cosa con la otra. `sync()` de Eloquent
 * admite las dos lecturas según cómo se le llame; escribir las tres
 * operaciones deja RB-3 a la vista en lugar de depender de saberse el detalle.
 *
 * Es también la diferencia con la ficha técnica de `T-028`, donde el reemplazo
 * completo **sí** era la respuesta: allí las filas no tenían nada que
 * conservar; aquí tienen una fecha que significa algo para otro módulo.
 *
 * ## Reescribe la lista entera, y por eso no hay conflicto que resolver
 *
 * Igual que `CategoryOrder`: dos personas componiendo el mismo catálogo a la
 * vez no tienen un conflicto, tienen una composición que acaba siendo la de
 * quien guardó después (SP-4). Montar bloqueo optimista para esto sería
 * rechazarle el guardado a alguien que no hizo nada malo.
 *
 * Lo que **no** hace esta clase es decidir si un identificador puede estar
 * ahí: llega con la lista ya resuelta contra el tenant. La pertenencia es de
 * `SyncCatalogProductsAction`, que es donde `REQ-ORG-002` RB-7 la pone, y es
 * lo que sostiene que el pivote no lleve `organization_id`.
 */
final class CatalogProductOrder
{
    /**
     * Deja el catálogo con exactamente estos productos, en este orden.
     *
     * @param  list<int>  $productIds  ya deduplicados y resueltos contra el tenant
     */
    public function apply(Catalog $catalog, array $productIds): void
    {
        $current = $this->currentIds($catalog);

        $removed = array_values(array_diff($current, $productIds));

        if ($removed !== []) {
            // RB-9 y SP-5 · quitar un producto del catálogo **no** toca los
            // apartados originados desde él: operan sobre su propio snapshot
            // (`REQ-LAY-002`). Por eso aquí no hay ningún recorrido que los
            // busque, y no debe haberlo.
            $catalog->products()->detach($removed);
        }

        $position = 1;

        foreach ($productIds as $productId) {
            if (in_array($productId, $current, true)) {
                // La fila se queda; lo único que cambia es dónde se pinta.
                $catalog->products()->updateExistingPivot($productId, ['position' => $position]);
            } else {
                // Entra hoy: el `created_at` lo pone la base con su
                // `useCurrent()`, que es lo que lo hace igual de fiable venga
                // de donde venga la escritura.
                $catalog->products()->attach($productId, ['position' => $position]);
            }

            $position++;
        }
    }

    /**
     * Deduplica preservando la primera aparición (SP-2).
     *
     * Una lista con el mismo identificador dos veces es un arrastre mal
     * serializado o un doble clic en «añadir», no una intención — y la PK
     * compuesta del pivote la rechazaría con un error de base de datos en vez
     * de con la lista que la persona quería (RB-4).
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public function deduplicate(array $productIds): array
    {
        return array_values(array_unique($productIds));
    }

    /**
     * Los productos que el catálogo tiene ahora.
     *
     * Va por la relación y no por la tabla: `Catalog::products()` lleva puesto
     * el *global scope* del producto (`REQ-ORG-002` RB-1), así que lo que se
     * compara con la lista pedida es lo que esta organización puede ver. Sin
     * orden, porque lo que se necesita de aquí es **quién está**, no dónde: el
     * orden lo fija la lista pedida y se recalcula entero.
     *
     * @return list<int>
     */
    private function currentIds(Catalog $catalog): array
    {
        return $catalog->products()
            ->pluck('products.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
