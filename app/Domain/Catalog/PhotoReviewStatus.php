<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum PhotoReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function blocksPublishing(): bool
    {
        return $this !== self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En revisión',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
        };
    }
}
