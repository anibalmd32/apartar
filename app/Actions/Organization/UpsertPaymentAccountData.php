<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Payment\PaymentMethod;

final readonly class UpsertPaymentAccountData
{
    public function __construct(
        public PaymentMethod $method,
        public ?string $bank,
        public string $holderName,
        public string $holderDocument,
        public string $identifier,
        public ?string $instructions,
        public bool $isActive = true,
        public ?int $position = null,
    ) {}

    public function comparableIdentifier(): string
    {
        return self::comparable($this->identifier);
    }

    public static function comparable(string $identifier): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9@.]/i', '', $identifier));
    }
}
