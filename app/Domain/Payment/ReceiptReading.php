<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Currency;
use App\Models\Bank;

final readonly class ReceiptReading
{
    public const AMOUNT_PATTERN = '/^\d{1,14}(\.\d{1,2})?$/';

    public const REFERENCE_PATTERN = '/^[A-Za-z0-9-]{4,40}$/';

    public function __construct(
        public ?string $amount = null,
        public ?Currency $currency = null,
        public ?string $paidOn = null,
        public ?string $reference = null,
        public ?string $bank = null,
    ) {}

    public static function nothing(): self
    {
        return new self;
    }

    /**
     * @param  array<mixed>  $answer
     */
    public static function fromAnswer(array $answer, string $earliest, string $latest): self
    {
        $text = fn (string $key): string => is_string($answer[$key] ?? null) ? trim($answer[$key]) : '';

        $amount = self::decimal($text('amount'));
        $amount = preg_match(self::AMOUNT_PATTERN, $amount) === 1 && (float) $amount > 0 ? $amount : null;

        $paidOn = $text('paid_on');
        $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) === 1 && checkdate((int) substr($paidOn, 5, 2), (int) substr($paidOn, 8, 2), (int) substr($paidOn, 0, 4))
            && $paidOn >= $earliest && $paidOn <= $latest ? $paidOn : null;

        $reference = (string) preg_replace('/\s+/', '', $text('reference'));
        $reference = preg_match(self::REFERENCE_PATTERN, $reference) === 1 ? $reference : null;

        return new self(
            amount: $amount,
            currency: self::currency($text('currency')),
            paidOn: $paidOn,
            reference: $reference,
            bank: Bank::matching($text('bank'))?->name,
        );
    }

    private static function decimal(string $raw): string
    {
        $raw = (string) preg_replace('/[^\d.,]/', '', $raw);
        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            return str_replace(',', '.', str_replace('.', '', $raw));
        }

        return str_replace(',', '', $raw);
    }

    private static function currency(string $raw): ?Currency
    {
        $key = strtoupper((string) preg_replace('/[^A-Za-z$]/', '', $raw));

        return match ($key) {
            'USD', '$', 'USDT', 'US$' => Currency::USD,
            'VES', 'BS', 'BSD', 'BSS', 'VEF' => Currency::VES,
            default => null,
        };
    }

    public function isEmpty(): bool
    {
        return $this->amount === null && $this->currency === null && $this->paidOn === null && $this->reference === null && $this->bank === null;
    }

    /**
     * @return array{amount: string|null, currency: string|null, paidAt: string|null, reference: string|null, bank: string|null}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency?->value,
            'paidAt' => $this->paidOn,
            'reference' => $this->reference,
            'bank' => $this->bank,
        ];
    }
}
