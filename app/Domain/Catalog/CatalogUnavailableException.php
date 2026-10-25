<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;
use RuntimeException;

final class CatalogUnavailableException extends RuntimeException
{
    public const STATUS = 422;

    private function __construct(public readonly Catalog $catalog)
    {
        parent::__construct('Este catálogo ya no está disponible.');
    }

    public static function for(Catalog $catalog): self
    {
        return new self($catalog);
    }
}
