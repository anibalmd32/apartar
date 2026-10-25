<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;

enum UnavailableReason: string
{
    case NotReservable = 'not_reservable';

    case OutOfStock = 'out_of_stock';

    case BusinessUnavailable = 'business_unavailable';

    public static function of(Product $product, ?FinancingPlan $plan, bool $businessAccepts = true): ?self
    {
        if (! $businessAccepts) {
            return self::BusinessUnavailable;
        }

        if ($plan === null || ! $product->status->canBeReserved()) {
            return self::NotReservable;
        }

        if ($product->stock <= 0) {
            return self::OutOfStock;
        }

        return null;
    }

    public function message(): string
    {
        return match ($this) {
            self::NotReservable => 'Este producto no se puede apartar. Escríbele al negocio si te interesa.',
            self::OutOfStock => 'Agotado: ahora mismo no quedan unidades para apartar.',
            self::BusinessUnavailable => 'Este negocio no está recibiendo apartados en este momento. Escríbele para consultar.',
        };
    }
}
