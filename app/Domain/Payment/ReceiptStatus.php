<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * En qué punto de la revisión está un comprobante (`REQ-PAY-001` RB-6,
 * `REQ-PAY-005`, `REQ-PAY-006`).
 *
 * Nace en `pending_review` y sale una sola vez, a `approved` o a `rejected`.
 * Solo `approved` mueve dinero, y lo mueve el `payment` que crea la
 * aprobación, no el comprobante.
 */
enum ReceiptStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Cómo se nombra en la bandeja del negocio (`REQ-PAY-004`).
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'En revisión',
            self::Approved => 'Aprobado',
            self::Rejected => 'Rechazado',
        };
    }

    /**
     * Si todavía espera al negocio.
     */
    public function awaitsReview(): bool
    {
        return $this === self::PendingReview;
    }

    /**
     * Si retiene la expiración de un apartado que espera la inicial
     * (`REQ-LAY-006` RB-1, RB-2): el cliente pagó a tiempo, y la demora, si la
     * hay, es del negocio. Un rechazado no retiene nada.
     */
    public function holdsExpiration(): bool
    {
        return match ($this) {
            self::PendingReview, self::Approved => true,
            self::Rejected => false,
        };
    }

    /**
     * Los estados que retienen la expiración, para consultarlos.
     *
     * @return list<self>
     */
    public static function holdingExpiration(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->holdsExpiration()));
    }
}
