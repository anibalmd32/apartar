<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Las seis alertas del portal (`REQ-LAY-023`), y en qué orden se presentan.
 *
 * El orden es de RB-2 y es una propiedad del tipo, no de quien las pinta: lo
 * que está a punto de perderse —la reserva sin inicial— va antes que lo que
 * ya se debe, y lo que ya se debe antes que lo que está por llegar. Una
 * invitación (`claim_available`) nunca tapa una deuda.
 */
enum PortalAlertKind: string
{
    case DownPaymentWindow = 'down_payment_window';
    case Overdue = 'overdue';
    case ReceiptRejected = 'receipt_rejected';
    case DueSoon = 'due_soon';
    case ReceiptPending = 'receipt_pending';
    case ClaimAvailable = 'claim_available';

    /**
     * Menor es antes (RB-2).
     */
    public function priority(): int
    {
        return match ($this) {
            self::DownPaymentWindow => 1,
            self::Overdue => 2,
            self::ReceiptRejected => 3,
            self::DueSoon => 4,
            self::ReceiptPending => 5,
            self::ClaimAvailable => 6,
        };
    }
}
