<?php

declare(strict_types=1);

namespace App\Domain\Safeguard;

use App\Models\Catalog;
use App\Models\Layaway;
use Illuminate\Database\Eloquent\Builder;

final readonly class SafeguardScope
{
    public static function appliesTo(Layaway $layaway): bool
    {
        $catalogs = Catalog::query()->where('safeguard_enabled', true);

        if ($layaway->catalog_id !== null) {
            return $catalogs->whereKey($layaway->catalog_id)->exists();
        }

        return $catalogs
            ->whereHas('products', fn (Builder $products): Builder => $products->withTrashed()->whereKey($layaway->product_id))
            ->exists();
    }
}
