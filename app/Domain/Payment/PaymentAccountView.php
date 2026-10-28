<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\PaymentAccount;

final readonly class PaymentAccountView
{
    private function __construct(
        public int $id,
        public PaymentMethod $method,
        public string $methodLabel,
        public string $identifierLabel,
        public ?string $bank,
        public ?string $bankLogoUrl,
        public ?string $bankCode,
        public string $holderName,
        public string $holderDocument,
        public string $identifier,
        public ?string $instructions,
        public bool $isActive,
        public int $position,
    ) {}

    public static function of(PaymentAccount $account): self
    {
        return new self(
            id: (int) $account->getKey(),
            method: $account->method,
            methodLabel: $account->method->label(),
            identifierLabel: $account->method->identifierLabel(),
            bank: $account->bank,
            bankLogoUrl: $account->bank_id === null ? null : $account->catalogBank?->logoUrl(),
            bankCode: $account->bank_id === null ? null : $account->catalogBank?->code,
            holderName: $account->holder_name,
            holderDocument: $account->holder_document,
            identifier: $account->identifier,
            instructions: $account->instructions,
            isActive: $account->is_active,
            position: $account->position,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method->value,
            'methodLabel' => $this->methodLabel,
            'identifierLabel' => $this->identifierLabel,
            'bank' => $this->bank,
            'bankLogoUrl' => $this->bankLogoUrl,
            'bankCode' => $this->bankCode,
            'holderName' => $this->holderName,
            'holderDocument' => $this->holderDocument,
            'identifier' => $this->identifier,
            'instructions' => $this->instructions,
            'isActive' => $this->isActive,
            'position' => $this->position,
        ];
    }
}
