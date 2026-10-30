<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Exchange\ManualRate;
use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAmounts;
use App\Domain\Payment\PaymentAttachment;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentOrigin;
use App\Models\PaymentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ManualPaymentData
{
    public string $note;

    /**
     * @throws InvalidArgumentException
     * @throws InvalidRateException
     */
    public function __construct(
        public PaymentMethod $method,
        public ?PaymentAccount $paymentAccount,
        public string $amount,
        public Currency $currency,
        public ?string $rateOverride,
        public CarbonImmutable $paidAt,
        string $note,
        public string $idempotencyKey,
        public ?int $confirmedExcessCents = null,
        public ?string $confirmedStaleRate = null,
        public ?string $confirmedRateDeviation = null,
        public ?string $bank = null,
        public ?string $reference = null,
        public ?UploadedFile $file = null,
    ) {
        PaymentAmounts::assertPositive($amount, $currency, 'del pago');
        SubmitReceiptData::assertReferenceFits($method, $bank, $reference);

        if ($rateOverride !== null) {
            new ManualRate($rateOverride);
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'La clave de idempotencia es el UUID que el formulario genera al montarse.'
            );
        }

        if ($confirmedExcessCents !== null && $confirmedExcessCents < 1) {
            throw new InvalidArgumentException('Un sobrante confirmado es de al menos un centavo.');
        }

        $this->note = (new StaffReason($note))->text;
    }

    public function manualRate(): ?ManualRate
    {
        return $this->rateOverride !== null && $this->currency !== Currency::USD ? new ManualRate($this->rateOverride) : null;
    }

    public function origin(?PaymentAttachment $attachment = null): PaymentOrigin
    {
        return new PaymentOrigin(
            $this->method,
            $this->paymentAccount?->getKey() === null ? null : (int) $this->paymentAccount->getKey(),
            $this->paidAt->startOfDay(),
            $this->bank,
            $this->reference,
            $attachment,
        );
    }

    public function money(): Money
    {
        return Money::fromDecimalString($this->amount, $this->currency);
    }
}
