<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentOrigin;
use Carbon\CarbonImmutable;

/**
 * Cómo se pagó un pago (`T-062`), en las columnas de `payments` tal cual.
 * Que la aprobación lo saque del comprobante lo afirma
 * `ApproveReceiptActionTest`; que el pago manual lo saque del formulario,
 * `RegisterManualPaymentActionTest`.
 */
it('pasa a las columnas del pago con su método, su cuenta y su día', function (): void {
    $origin = new PaymentOrigin(PaymentMethod::Zelle, 7, CarbonImmutable::parse('2026-10-05'));

    expect($origin->toColumns())->toBe(['payment_method' => 'zelle', 'payment_account_id' => 7, 'paid_on' => '2026-10-05']);
});

it('guarda el día tal cual, sin llevarlo a ninguna zona', function (): void {
    $origin = new PaymentOrigin(PaymentMethod::EfectivoUsd, null, CarbonImmutable::parse('2026-10-05 23:30:00', 'Asia/Tokyo'));

    expect($origin->toColumns())->toBe(['payment_method' => 'efectivo_usd', 'payment_account_id' => null, 'paid_on' => '2026-10-05']);
});
