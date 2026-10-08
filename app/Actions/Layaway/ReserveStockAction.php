<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductLock;
use App\Models\Product;
use App\Support\ModelState;
use InvalidArgumentException;

/**
 * Inmoviliza las unidades de un apartado (`REQ-LAY-003`).
 *
 * **Se reserva al crear, no al activar** (RB-1): si se esperara a la inicial,
 * dos clientes apartarían la última unidad y solo uno pagaría. La llama
 * `CreateLayawayAction` (`T-045`) **dentro de su transacción**, y no abre una
 * propia: una reserva confirmada sin su apartado sería stock desaparecido.
 *
 * El orden, que es lo que importa (RB-8): bloquear, comprobar, descontar.
 * Comprobar sin bloquear deja que dos peticiones vean la misma última unidad;
 * bloquear después de comprobar es lo mismo con otra forma.
 * `ReserveStockConcurrencyTest` lo provoca con dos conexiones.
 */
final class ReserveStockAction
{
    /**
     * Descuenta `$quantity` de las disponibles, o lanza sin tocar nada.
     *
     * @return Product el producto bloqueado, ya con el stock descontado
     *
     * @throws InsufficientStockException si no quedan `$quantity` disponibles (RB-4, SP-1)
     * @throws \LogicException fuera de una transacción
     */
    public function handle(Product $product, int $quantity): Product
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Se reserva al menos una unidad.');
        }

        // RB-8 · aunque quien llama ya lo tenga: es reentrante.
        $locked = ProductLock::acquire((int) $product->getKey());

        // RB-4 · la comprobación se hace **sobre la fila bloqueada**, que es la
        // única cifra cierta: la del modelo que llega pudo leerse antes de que
        // otra transacción se llevara la unidad.
        if ($locked->stock < $quantity) {
            throw InsufficientStockException::for($locked, $quantity);
        }

        $stock = $locked->stock - $quantity;

        // `toBase()` y no `save()`: reservar no es editar el producto. Un
        // `save()` movería `updated_at` y la marca de última modificación
        // (`REQ-BOF-020` SP-7) diría que lo tocó quien apartó. El global scope
        // sigue puesto.
        Product::query()->whereKey($locked->getKey())->toBase()->update(['stock' => $stock]);

        ModelState::adopt($locked, ['stock' => $stock] + $locked->getAttributes());

        return $locked;
    }
}
