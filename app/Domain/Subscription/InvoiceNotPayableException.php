<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\SubscriptionInvoice;
use RuntimeException;

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
