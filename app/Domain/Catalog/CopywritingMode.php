<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum CopywritingMode: string
{
    case Suggest = 'suggest';
    case Improve = 'improve';

    public function needsDescription(): bool
    {
        return $this === self::Improve;
    }
}
