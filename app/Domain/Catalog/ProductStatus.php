<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum ProductStatus: string
{
    case Draft = 'draft';

    case Published = 'published';

    case Archived = 'archived';

    public function isVisibleInCatalog(): bool
    {
        return match ($this) {
            self::Published => true,
            self::Draft, self::Archived => false,
        };
    }

    public function canBeReserved(): bool
    {
        return match ($this) {
            self::Published => true,
            self::Draft, self::Archived => false,
        };
    }

    public function countsTowardPlanLimit(): bool
    {
        return match ($this) {
            self::Draft, self::Published => true,
            self::Archived => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicado',
            self::Archived => 'Archivado',
        };
    }
}
