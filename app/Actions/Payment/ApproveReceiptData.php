<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Exchange\ManualRate;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAmounts;
use InvalidArgumentException;

final readonly class ApproveReceiptData
{
    public const NOTE_MAX = 500;

    /**
     * @throws InvalidArgumentException
     * @throws InvalidRateException
     */
    public function __construct(
        public string $settledAmount,
        public Currency $settledCurrency,
        public ?string $rateOverride,
        public ?string $note,
        public ?int $confirmedExcessCents = null,
        public ?string $confirmedStaleRate = null,
    ) {
        PaymentAmounts::assertPositive($settledAmount, $settledCurrency, 'conciliado');

        if ($rateOverride !== null) {
            new ManualRate($rateOverride);
        }

        if ($confirmedExcessCents !== null && $confirmedExcessCents < 1) {
            throw new InvalidArgumentException('Un sobrante confirmado es de al menos un centavo.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidArgumentException(sprintf('La nota tiene como mucho %d caracteres.', self::NOTE_MAX));
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
