<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Models\Product;
use Carbon\CarbonImmutable;

trait DescribesProductOffer
{
    public const NEW_FOR_DAYS = 14;

    public const FEW_LEFT = 10;

    private function isNew(Product $product): bool
    {
        $published = $product->published_at;
        $now = CarbonImmutable::now();

        return $published !== null && $published->lte($now) && $published->gt($now->subDays(self::NEW_FOR_DAYS));
    }

    private function savingsPercent(Product $product): ?int
    {
        $before = $product->compare_at_price_cents?->cents;
        $now = $product->price_cents->cents;

        if ($before === null || $before <= $now) {
            return null;
        }

        return (int) round(100 - ($now * 100 / $before));
    }

    private function stockLeft(Product $product): ?int
    {
        return $product->stock > 0 && $product->stock <= self::FEW_LEFT ? $product->stock : null;
    }
}
