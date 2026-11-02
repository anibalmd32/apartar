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
use Illuminate\Support\Collection;

final readonly class ReceiptsInReview
{
    public function __construct(
        public Money $amount,
        public int $count,
        public ?CarbonImmutable $lastSubmittedAt,
        public ?Money $declaredVes = null,
    ) {}

    public static function of(Layaway $layaway, Organization $organization, ExchangeRateService $rates): self
    {
        return self::ofLayaways([$layaway], $organization, $rates)[(int) $layaway->getKey()];
    }

    /**
     * @param  list<Layaway>  $layaways
     * @return array<int, self>
     */
    public static function ofLayaways(array $layaways, Organization $organization, ExchangeRateService $rates): array
    {
        $ids = array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways);

        $receipts = $ids === [] ? new Collection : Receipt::query()
            ->whereIn('layaway_id', $ids)
            ->where('status', ReceiptStatus::PendingReview)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('layaway_id');

        $byLayaway = [];

        foreach ($ids as $id) {
            $byLayaway[$id] = self::summing($receipts->get($id, new Collection)->all(), $organization, $rates);
        }

        return $byLayaway;
    }

    public function any(): bool
    {
        return $this->count > 0;
    }

    /**
     * @param  array<array-key, Receipt>  $receipts
     */
    private static function summing(array $receipts, Organization $organization, ExchangeRateService $rates): self
    {
        $cents = 0;
        $ves = new Money(0, Currency::VES);
        $last = null;

        foreach ($receipts as $receipt) {
            $declared = Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency);
            $cents += self::dollarsOf($declared, $receipt, $organization, $rates);
            $ves = $ves !== null && $declared->currency === Currency::VES ? $ves->add($declared) : null;
            $last = $receipt->created_at === null ? $last : CarbonImmutable::instance($receipt->created_at);
        }

        return new self(new Money($cents), count($receipts), $last, $receipts === [] ? null : $ves);
    }

    private static function dollarsOf(Money $declared, Receipt $receipt, Organization $organization, ExchangeRateService $rates): int
    {
        if ($declared->currency === Currency::USD) {
            return $declared->cents;
        }

        $rate = $rates->on($organization, $receipt->paid_at) ?? $rates->current($organization);

        return $rate === null ? 0 : $declared->convertTo(Currency::USD, $rate)->cents;
    }
}
