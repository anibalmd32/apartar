<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentNotApplicableException;

it('responde con un 422', function (): void {
    expect(PaymentNotApplicableException::STATUS)->toBe(422);
});

it('nombra el estado del apartado que ya no cobra', function (): void {
    expect(PaymentNotApplicableException::layawayNotCollecting(LayawayStatus::Completed)->getMessage())->toContain('Pagado');
});

it('dice el importe y la tasa de una conversión a cero', function (): void {
    $message = PaymentNotApplicableException::convertsToZero(new Money(1, Currency::VES), '42.50')->getMessage();

    expect($message)->toContain('1 céntimos')->toContain('VES')->toContain('42.50');
});

it('dice lo pagado y la inicial que falta cubrir', function (): void {
    $message = PaymentNotApplicableException::belowDownPayment(new Money(4_999), new Money(5_000))->getMessage();

    expect($message)->toContain('4999')->toContain('5000');
});
