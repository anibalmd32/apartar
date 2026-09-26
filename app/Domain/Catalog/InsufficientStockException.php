<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use RuntimeException;

/**
 * No quedan unidades para lo que se quería apartar (`REQ-LAY-003` RB-4, SP-1).
 *
 * La lanza `ReserveStockAction` **bajo el bloqueo de la fila**, que es donde
 * la cifra es cierta: la que vio el cliente al abrir el formulario pudo ser
 * otra, y la de dos peticiones simultáneas por la última unidad es la misma
 * para las dos hasta que una espera a la otra.
 *
 * `422` con las disponibles reales (`REQ-LAY-001` SP-1): el cliente puede
 * reducir la cantidad y volver a enviar. Es la puerta de las existencias; la
 * del estado del producto es `ProductNotAvailableException`, y un producto
 * agotado sigue publicado (RB-5).
 */
final class InsufficientStockException extends RuntimeException
{
    /** Código HTTP con el que se responde (SP-1). */
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
