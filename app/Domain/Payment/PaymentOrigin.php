<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Receipt;
use Carbon\CarbonImmutable;

final readonly class PaymentOrigin
{
    public function __construct(
        public PaymentMethod $method,
        public ?int $paymentAccountId,
        public CarbonImmutable $paidOn,
    ) {}

    public static function ofReceipt(Receipt $receipt): self
    {
        return new self(
            $receipt->payment_method,
            $receipt->payment_account_id,
            CarbonImmutable::parse($receipt->paid_at->toDateString()),
        );
    }

    /**
     * @return array{payment_method: string, payment_account_id: int|null, paid_on: string}
     */
    public function toColumns(): array
    {
        return [
            'payment_method' => $this->method->value,
            'payment_account_id' => $this->paymentAccountId,
            'paid_on' => $this->paidOn->toDateString(),
        ];
    }
}
