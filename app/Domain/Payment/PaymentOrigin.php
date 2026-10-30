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
        public ?string $bank = null,
        public ?string $reference = null,
        public ?PaymentAttachment $attachment = null,
    ) {}

    public static function ofReceipt(Receipt $receipt): self
    {
        return new self(
            $receipt->payment_method,
            $receipt->payment_account_id,
            CarbonImmutable::parse($receipt->paid_at->toDateString()),
            $receipt->bank,
            $receipt->reference,
        );
    }

    /**
     * @return array{payment_method: string, payment_account_id: int|null, paid_on: string, bank: string|null, reference: string|null, file_path: string|null, file_hash: string|null, file_mime: string|null, file_size: int|null}
     */
    public function toColumns(): array
    {
        return [
            'payment_method' => $this->method->value,
            'payment_account_id' => $this->paymentAccountId,
            'paid_on' => $this->paidOn->toDateString(),
            'bank' => $this->bank,
            'reference' => $this->reference,
            ...($this->attachment?->toColumns() ?? ['file_path' => null, 'file_hash' => null, 'file_mime' => null, 'file_size' => null]),
        ];
    }
}
