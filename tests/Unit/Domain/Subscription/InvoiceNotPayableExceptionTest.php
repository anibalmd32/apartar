<?php

declare(strict_types=1);

use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Subscription\InvoiceStatus;

/** `REQ-SUB-006` SP-6, SP-8 · `422`, y dice si está pagada o anulada. */
it('dice por qué la factura ya no se paga', function (): void {
    expect((new InvoiceNotPayableException(InvoiceStatus::Paid))->getMessage())->toBe('Esta factura ya está pagada.')
        ->and((new InvoiceNotPayableException(InvoiceStatus::Void))->getMessage())->toBe('Esta factura está anulada y ya no se paga.')
        ->and(InvoiceNotPayableException::STATUS)->toBe(422)
        ->and(new InvoiceNotPayableException(InvoiceStatus::Paid))->toBeInstanceOf(RuntimeException::class);
});
