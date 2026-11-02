<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use Carbon\CarbonImmutable;

final readonly class ReceiptsInReview
{
    public function __construct(
        public Money $amount,
        public int $count,
        public ?CarbonImmutable $lastSubmittedAt,
    ) {}

    public static function of(Layaway $layaway, Organization $organization, ExchangeRateService $rates): self
    {
        $receipts = Receipt::query()
            ->where('layaway_id', $layaway->getKey())
            ->where('status', ReceiptStatus::PendingReview)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $cents = 0;
        $last = null;

        foreach ($receipts as $receipt) {
            $cents += self::dollarsOf($receipt, $organization, $rates);
            $last = $receipt->created_at === null ? $last : CarbonImmutable::instance($receipt->created_at);
        }

        return new self(new Money($cents), $receipts->count(), $last);
    }

    public function any(): bool
    {
        return $this->count > 0;
    }

    private static function dollarsOf(Receipt $receipt, Organization $organization, ExchangeRateService $rates): int
    {
        $declared = Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency);

        if ($declared->currency === Currency::USD) {
            return $declared->cents;
        }

        $rate = $rates->on($organization, $receipt->paid_at) ?? $rates->current($organization);

        return $rate === null ? 0 : $declared->convertTo(Currency::USD, $rate)->cents;
    }
}
