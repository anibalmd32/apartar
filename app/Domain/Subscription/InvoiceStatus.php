<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Estado de una factura de la suscripción (`REQ-SUB-006`, `InvoiceRow.status`).
 *
 * `overdue` **no se guarda**: es una factura `pending` con el vencimiento
 * pasado, y se deriva al leerla (`SubscriptionInvoice::displayStatus()`). Un
 * estado guardado que depende del reloj necesita un job que lo mantenga, y el
 * día que ese job no corre la pantalla miente; derivado, no puede.
 */
enum InvoiceStatus: string
{
    /** Emitida y sin pagar del todo; puede haber un pago parcial (SP-2). */
    case Pending = 'pending';

    /** Con un comprobante esperando a la plataforma. */
    case UnderReview = 'under_review';

    case Paid = 'paid';

    /** Solo para mostrar: `pending` con el vencimiento pasado. */
    case Overdue = 'overdue';

    /** Anulada (RB-3): corregir una factura es anularla y emitir otra. */
    case Void = 'void';

    /**
     * Indica si todavía se le puede subir un comprobante (RB-7: también tras
     * un rechazo, que la deja pendiente).
     */
    public function acceptsReceipts(): bool
    {
        return match ($this) {
            self::Pending, self::UnderReview, self::Overdue => true,
            self::Paid, self::Void => false,
        };
    }

    /**
     * Indica si es una deuda abierta: lo que el aviso del panel persigue.
     */
    public function isOutstanding(): bool
    {
        return $this->acceptsReceipts();
    }
}
