<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Catalog\CatalogVisibility;
use App\Models\Catalog;
use DomainException;

final class CatalogNotSubscribableException extends DomainException
{
    public const STATUS = 422;

    public const MESSAGE = 'Este catálogo ya no está disponible, así que no se puede seguir.';

    private function __construct(public readonly CatalogVisibility $visibility)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function for(Catalog $catalog): self
    {
        return new self($catalog->visibility());
    }
}
