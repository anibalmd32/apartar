<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum CatalogVisibility: string
{
    case Draft = 'draft';

    case Listed = 'listed';

    case Unlisted = 'unlisted';

    case Expired = 'expired';

    public function isPubliclyAccessible(): bool
    {
        return match ($this) {
            self::Listed, self::Unlisted => true,
            self::Draft, self::Expired => false,
        };
    }

    public function appearsInStorefront(): bool
    {
        return $this === self::Listed;
    }

    public function countsTowardPlanLimit(): bool
    {
        return match ($this) {
            self::Listed, self::Unlisted => true,
            self::Draft, self::Expired => false,
        };
    }
}
