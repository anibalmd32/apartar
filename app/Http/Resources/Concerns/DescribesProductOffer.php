<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Domain\Catalog\NewArrival;
use App\Domain\Catalog\Savings;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait DescribesProductOffer
{
    public const FEW_LEFT = 10;

    private function isNew(Product $product): bool
    {
        $added = $product->getAttribute('catalog_added_at') ?? DB::table('catalog_product')
            ->where('catalog_id', $this->catalog->getKey())
            ->where('product_id', $product->getKey())
            ->value('created_at');

        return NewArrival::isNew(NewArrival::enteredAt($product->published_at, is_string($added) ? CarbonImmutable::parse($added) : null));
    }

    private function savingsPercent(Product $product): ?int
    {
        return Savings::percent($product->price_cents, $product->compare_at_price_cents);
    }

    private function stockLeft(Product $product): ?int
    {
        return $product->stock > 0 && $product->stock <= self::FEW_LEFT ? $product->stock : null;
    }
}
