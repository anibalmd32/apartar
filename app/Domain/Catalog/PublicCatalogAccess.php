<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class PublicCatalogAccess
{
    /**
     * @throws ModelNotFoundException<Catalog>
     * @throws CatalogExpiredException
     */
    public static function ensureOpen(Catalog $catalog): void
    {
        match ($catalog->visibility()) {
            CatalogVisibility::Draft => throw (new ModelNotFoundException)->setModel(Catalog::class, [$catalog->slug]),
            CatalogVisibility::Expired => throw CatalogExpiredException::of($catalog),
            CatalogVisibility::Listed, CatalogVisibility::Unlisted => null,
        };
    }
}
