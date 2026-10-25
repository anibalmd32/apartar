<?php

declare(strict_types=1);

use App\Domain\Subscription\InvoiceAlreadyPaidException;

it('es un 422 que dice por qué', function (): void {
    expect(InvoiceAlreadyPaidException::STATUS)->toBe(422)
        ->and((new InvoiceAlreadyPaidException)->getMessage())->toContain('revertir el pago');
});
