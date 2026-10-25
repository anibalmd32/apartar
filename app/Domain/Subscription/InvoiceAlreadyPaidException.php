<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

final class InvoiceAlreadyPaidException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct()
    {
        parent::__construct('La factura ya está pagada: no se puede anular sin revertir el pago, y eso no existe todavía.');
    }
}
