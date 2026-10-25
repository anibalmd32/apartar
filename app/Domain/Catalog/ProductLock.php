<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProductLock
{
    /**
     * @throws ModelNotFoundException
     * @throws LogicException
     */
    public static function acquire(int $productId): Product
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'El producto se bloquea dentro de la transacción que mueve su stock. '
                .'Un FOR UPDATE fuera de una transacción se suelta en el acto y no serializa nada.'
            );
        }

        return Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
    }
}
