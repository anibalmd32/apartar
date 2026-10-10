<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\SubscriptionInvoice;
use RuntimeException;

/**
 * La factura ya no admite pagos: está pagada o anulada (`REQ-SUB-006` SP-6,
 * SP-8, `T-069`).
 *
 * `422`, como el resto de negativas de negocio: la petición estaba bien
 * formada y llega tarde. Es también lo que recibe el segundo comprobante de
 * una factura que otro ya liquidó.
 */
final class InvoiceNotPayableException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct(public readonly InvoiceStatus $status)
    {
        parent::__construct($status === InvoiceStatus::Void
            ? 'Esta factura está anulada y ya no se paga.'
            : 'Esta factura ya está pagada.');
    }

    public static function of(SubscriptionInvoice $invoice): self
    {
        return new self($invoice->status);
    }
}
