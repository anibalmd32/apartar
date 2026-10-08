<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Catalog\ProductLock;
use App\Models\Layaway;
use App\Models\Product;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Devuelve al stock las unidades de un apartado que expiró o se canceló
 * (`REQ-LAY-003` RB-2).
 *
 * La llama `LayawayStateMachine` en la misma transacción que la transición a
 * `expired` o `cancelled`, igual que mueve el cupo del plan: así ningún
 * llamante —el job de expiración de `T-047`, la cancelación de `T-053`— puede
 * cambiar el estado y olvidarse de la unidad. **Completar no libera**: la
 * unidad se entregó (`LayawayStatus::releasesStock()`).
 *
 * ## Idempotente por `stock_released_at` (SP-3)
 *
 * El job de expiración se reintenta, y una segunda liberación del mismo
 * apartado inventaría stock. La marca se comprueba y se escribe **bajo el
 * bloqueo de la fila del apartado**, así que dos liberaciones simultáneas no
 * pueden verla vacía las dos.
 *
 * ## Con la organización suspendida también (SP-7)
 *
 * Devolver stock es mantenimiento de datos, no una operación de negocio: aquí
 * no se pregunta por la suscripción ni por el estado de la organización.
 *
 * ## Lo que se devuelve es lo que está
 *
 * Si el negocio declaró menos existencias de las que tenía apartadas
 * (`REQ-LAY-003` SP-2), `stock_shortfall` guarda cuántas apartadas **no
 * existen**. Liberar una de esas no devuelve nada a la venta: la liberación
 * **absorbe primero el faltante** y solo suma a `stock` lo que queda. Sin
 * esto, cancelar el apartado de una nevera que ya no estaba pondría a la venta
 * esa misma nevera.
 */
final class ReleaseStockAction
{
    /**
     * @throws LogicException si el apartado no está en un estado que libere (RB-2, SP-4)
     */
    public function handle(Layaway $layaway): void
    {
        DB::transaction(function () use ($layaway): void {
            // RB-8 · el producto antes que el apartado, aunque la máquina ya
            // tenga los dos: es la regla, y pedirlos otra vez es gratis.
            $product = $this->lockProductOf($layaway);

            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->stock_released_at !== null) {
                // SP-3 · ya se devolvió. No es un error: es el reintento.
                return;
            }

            if (! $locked->status->releasesStock()) {
                throw new LogicException(sprintf(
                    'El apartado %d está en «%s» y ese estado no devuelve stock (REQ-LAY-003 RB-2): solo expirar y '
                    .'cancelar lo hacen. Completar significa que la unidad se entregó.',
                    $locked->getKey(),
                    $locked->status->value,
                ));
            }

            $quantity = (int) $locked->getAttribute('quantity');
            $absorbed = min($product->stock_shortfall, $quantity);

            // `toBase()`: devolver stock no es editar el producto, y no puede
            // mover su marca de última modificación (`REQ-BOF-020` SP-7).
            Product::query()->whereKey($product->getKey())->toBase()->update([
                'stock' => $product->stock + $quantity - $absorbed,
                'stock_shortfall' => $product->stock_shortfall - $absorbed,
            ]);

            $releasedAt = CarbonImmutable::now();

            Layaway::query()->whereKey($locked->getKey())->update(['stock_released_at' => $releasedAt]);

            ModelState::adopt($layaway, ['stock_released_at' => $releasedAt->format('Y-m-d H:i:s')] + $layaway->getAttributes());
        });
    }

    /**
     * Bloquea el producto del apartado **sin haber bloqueado el apartado**
     * (RB-8). Es lo que llama `LayawayStateMachine` antes de su propio
     * `FOR UPDATE` cuando la transición va a devolver stock.
     */
    public function lockProductOf(Layaway $layaway): Product
    {
        return ProductLock::acquire($this->productIdOf($layaway));
    }

    /**
     * `product_id` no cambia nunca (`REQ-LAY-002` RB-8), así que el del modelo
     * que llega vale para bloquear **antes** de leer el apartado. Si llega sin
     * él —cargado con un `select` corto—, se lee sin bloquear.
     */
    private function productIdOf(Layaway $layaway): int
    {
        $productId = $layaway->getAttribute('product_id')
            ?? Layaway::query()->whereKey($layaway->getKey())->value('product_id');

        if (! is_numeric($productId)) {
            throw new LogicException("El apartado {$layaway->getKey()} no tiene producto.");
        }

        return (int) $productId;
    }
}
