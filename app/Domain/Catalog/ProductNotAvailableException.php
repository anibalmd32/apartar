<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use RuntimeException;

final class ProductNotAvailableException extends RuntimeException
{
    public const STATUS = 422;

    private function __construct(
        public readonly Product $product,
        public readonly ProductStatus $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @throws self
     */
    public static function guard(Product $product): void
    {
        if ($product->status->canBeReserved()) {
            return;
        }

        throw self::for($product);
    }

    public static function for(Product $product): self
    {
        return new self(
            $product,
            $product->status,
            sprintf(
                '«%s» ya no está disponible. El negocio lo retiró mientras tenías la página abierta; '
                .'vuelve al catálogo para ver lo que sí está.',
                $product->name,
            ),
        );
    }
}
