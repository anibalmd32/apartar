<?php

declare(strict_types=1);

namespace App\Domain\Payment;

enum ReceiptStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'En revisión',
            self::Approved => 'Aprobado',
            self::Rejected => 'Rechazado',
        };
    }

    public function awaitsReview(): bool
    {
        return $this === self::PendingReview;
    }

    public function holdsExpiration(): bool
    {
        return match ($this) {
            self::PendingReview, self::Approved => true,
            self::Rejected => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function holdingExpiration(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->holdsExpiration()));
    }
}
