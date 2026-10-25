<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Exchange\ManualRate;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAmounts;
use InvalidArgumentException;

final readonly class ApproveSubscriptionReceiptData
{
    /**
     * @throws InvalidArgumentException
     * @throws InvalidRateException
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

    public function manualRate(): ?ManualRate
    {
        return $this->rateOverride !== null && $this->settledCurrency !== Currency::USD ? new ManualRate($this->rateOverride) : null;
    }
}
