<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Exchange\ManualRate;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAmounts;
use InvalidArgumentException;

/**
 * Lo que la plataforma concilia al aprobar un comprobante de suscripción
 * (`REQ-SUB-006` RB-4, SP-2, `REQ-PLT-004` RB-3, `T-069`): el importe que de
 * verdad entró, en su moneda, y —si fue en bolívares y de otro día— la tasa
 * con la que convertirlo (`REQ-PLT-004` happy path: «ajusta la tasa si el pago
 * fue de otro día»). Es la forma de `ApproveReceiptData` sin lo que solo tiene
 * sentido con un apartado.
 */
final readonly class ApproveSubscriptionReceiptData
{
    /**
     * @param  string|null  $rateOverride  tasa a aplicar; por defecto la vigente de la plataforma
     *
     * @throws InvalidArgumentException si el importe no tiene forma válida
     * @throws InvalidRateException si la tasa escrita a mano no es una tasa
     */
    public function __construct(
        public string $settledAmount,
        public Currency $settledCurrency,
        public ?string $rateOverride = null,
    ) {
        PaymentAmounts::assertPositive($settledAmount, $settledCurrency, 'conciliado');

        if ($rateOverride !== null) {
            new ManualRate($rateOverride);
        }
    }

    public function settled(): Money
    {
        return Money::fromDecimalString($this->settledAmount, $this->settledCurrency);
    }

    /**
     * La tasa escrita a mano, solo si hay algo que convertir (como en
     * `ApproveReceiptData::manualRate()`).
     */
    public function manualRate(): ?ManualRate
    {
        return $this->rateOverride !== null && $this->settledCurrency !== Currency::USD ? new ManualRate($this->rateOverride) : null;
    }
}
