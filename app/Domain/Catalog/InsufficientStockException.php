<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    public const STATUS = 422;

    private function __construct(
        public readonly Product $product,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct($available === 0
            ? 'Ya no quedan unidades disponibles de este producto.'
            : sprintf(
                'Solo %s de este producto. Reduce la cantidad para apartar.',
                $available === 1 ? 'queda 1 unidad disponible' : "quedan {$available} unidades disponibles",
            ));
    }

    public static function for(Product $product, int $requested): self
    {
        return new self($product, $requested, $product->stock);
    }
}
