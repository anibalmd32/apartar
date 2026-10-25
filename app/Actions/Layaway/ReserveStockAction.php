<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductLock;
use App\Models\Product;
use App\Support\ModelState;
use InvalidArgumentException;

final class ReserveStockAction
{
    /**
     * @throws InsufficientStockException
     * @throws \LogicException
     */
    public function handle(Product $product, int $quantity): Product
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Se reserva al menos una unidad.');
        }

        $locked = ProductLock::acquire((int) $product->getKey());

        if ($locked->stock < $quantity) {
            throw InsufficientStockException::for($locked, $quantity);
        }

        $stock = $locked->stock - $quantity;

        Product::query()->whereKey($locked->getKey())->toBase()->update(['stock' => $stock]);

        ModelState::adopt($locked, ['stock' => $stock] + $locked->getAttributes());

        return $locked;
    }
}
