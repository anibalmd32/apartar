<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\PaymentNotVoidableException;

it('dice por qué no se anula el pago de un apartado cerrado', function (): void {
    expect(PaymentNotVoidableException::layawayClosed(LayawayStatus::Cancelled)->getMessage())
        ->toBe('El apartado está «cancelado»: sus pagos ya no se anulan.');
});

it('pide reponer la inicial antes de anular el pago que la cubrió', function (): void {
    expect(PaymentNotVoidableException::coversDownPayment()->getMessage())
        ->toBe('Este pago cubrió la inicial: para anularlo, registra antes el pago correcto de la inicial o cancela el apartado.')
        ->and(PaymentNotVoidableException::alreadyVoided()->getMessage())->toBe('Este pago ya está anulado.');
});
