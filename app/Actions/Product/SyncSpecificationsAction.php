<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductSpecification;
use Illuminate\Validation\ValidationException;

/**
 * Reemplaza entera la ficha técnica de un producto (`REQ-BOF-021`).
 *
 * ## Borra y reinserta, y no es pereza
 *
 * RB-5 lo pide así. Un *diff* fila por fila tendría que distinguir «esta fila
 * cambió de texto» de «esta cambió de sitio» de «esta es nueva y aquella se
 * fue», y cada uno de esos casos es un estado intermedio en el que la ficha de
 * un producto puede quedarse a medias. El reemplazo tiene **un solo** estado
 * intermedio y está dentro de una transacción, que es lo que hace cumplible
 * SP-4: un fallo a mitad deja las especificaciones anteriores íntegras.
 *
 * De ahí salen dos consecuencias que la spec ya declara:
 *
 *  - **SP-7 · gana el último, sin *merge*.** Dos personas guardando el mismo
 *    producto no negocian filas: la última lista es la lista.
 *  - **RB-4 · `position` se recalcula contiguo desde 1** en cada guardado. No
 *    se conserva el número que traía la fila ni se usa el índice del array del
 *    formulario como verdad: el orden lo fija el arrastre y el servidor lo
 *    normaliza, igual que `CategoryOrder` hace con las categorías.
 *
 * ## Cero filas borra, y hay que decirlo
 *
 * SP-3: guardar con la lista vacía es válido y **elimina todas**; la ficha
 * pública omite entonces la sección entera. Tratarlo como «no hay nada que
 * hacer» y saltarse el borrado haría que quitar la última fila no la quitara,
 * que es el bug que este párrafo existe para prevenir.
 *
 * ## El tope de 50 es técnico
 *
 * RB-3. **No** es una métrica de plan y no pasa por `LimitEnforcer`: nadie
 * desbloquea la fila 51 pagando más. Es `422` y punto. El mismo tope lo
 * comprueba `ValidatesSpecifications` para que el error llegue junto al campo;
 * aquí se vuelve a mirar porque esta acción también la llama quien no pasa por
 * un formulario.
 *
 * ## Y no tiene endpoint
 *
 * RB-8: se guarda **con el producto**, dentro de la transacción de
 * `UpsertProductAction`. Un endpoint propio permitiría una ficha guardada
 * sobre un producto que no llegó a guardarse.
 */
final class SyncSpecificationsAction
{
    /**
     * Tope de filas por producto (RB-3).
     *
     * Cincuenta es un límite técnico: una ficha más larga que eso deja de
     * leerse y empieza a ser un documento, y la tabla de dos columnas de
     * `REQ-CAT-004` RB-5 no es el sitio para eso.
     */
    public const MAX_ROWS = 50;

    /**
     * Deja la ficha del producto exactamente como dice `$rows`.
     *
     * No autoriza ni abre transacción propia: las dos cosas las pone
     * `UpsertProductAction`, que es su único llamante de aplicación y quien
     * garantiza que la ficha y el producto se guardan o se descartan juntos
     * (RB-8). `DB::transaction()` aquí anidaría un *savepoint* y el rollback
     * de SP-4 dejaría de alcanzar al producto.
     *
     * @param  list<SpecificationInput>  $rows  la lista completa, en su orden
     *
     * @throws ValidationException si se pasan de 50 filas (RB-3, SP-2)
     */
    public function handle(Product $product, array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'specifications' => sprintf(
                    'Una ficha técnica admite hasta %d características y llegaron %d.',
                    self::MAX_ROWS,
                    count($rows),
                ),
            ]);
        }

        $productId = (int) $product->getKey();

        // El borrado va por el modelo y no por `DB::table()`, así que lleva el
        // global scope del tenant puesto: la ficha de un producto de otra
        // organización no existe desde aquí ni para borrarla
        // (`REQ-ORG-002` RB-1).
        ProductSpecification::query()->where('product_id', $productId)->delete();

        if ($rows === []) {
            // SP-3 · cero filas es un guardado válido, y el borrado de arriba
            // **ya ocurrió**: salir antes de tiempo sería saltarse el insert,
            // no el delete.
            return;
        }

        // RB-4 · la posición sale del **orden de la lista**, no del número que
        // traía la fila ni del índice que tuviera en el formulario: el orden
        // lo fija el arrastre y el servidor lo normaliza a 1..n.
        $position = 1;

        foreach ($rows as $row) {
            // Fila a fila y no con un `insert()` en lote, a propósito: el
            // relleno de `organization_id` lo hace el evento `creating` del
            // trait, y una inserción masiva se lo salta. Serían cincuenta
            // consultas como mucho —el tope de RB-3— a cambio de que la tabla
            // siga siendo del tenant por construcción y no por acordarse.
            ProductSpecification::query()->create($row->toAttributes($productId, $position));

            $position++;
        }
    }
}
