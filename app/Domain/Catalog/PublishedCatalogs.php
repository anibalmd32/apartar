<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;

class PublishedCatalogs
{
    public function exist(): bool
    {
        return Catalog::query()->whereNotNull('published_at')->exists();
    }
}
