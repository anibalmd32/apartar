<?php

declare(strict_types=1);

namespace App\Domain\Safeguard;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\Organization;
use App\Models\Payment;
use Carbon\CarbonImmutable;

final class ParallelEquivalents
{
    /**
     * @var array<string, RateSnapshot|null>
     */
    private array $rates = [];

    public function __construct(private readonly ExchangeRateService $service) {}

    public function of(Payment $payment, Organization $organization): ParallelEquivalent
    {
        $credited = $payment->amount_cents_usd;

        if ($payment->declared_currency === Currency::USD) {
            return new ParallelEquivalent($credited, $credited, null);
        }

        if ($payment->rate_source === RateSource::Parallel) {
            return new ParallelEquivalent($credited, $credited, $payment->rate_applied);
        }

        $rate = $this->rateOn($organization, self::dayOf($payment, $organization));

        if ($rate === null) {
            return ParallelEquivalent::missing($credited);
        }

        $bolivars = Money::fromDecimalString($payment->declared_amount, Currency::VES);

        return new ParallelEquivalent($credited, $bolivars->convertTo(Currency::USD, $rate), $rate->rate);
    }

    public static function dayOf(Payment $payment, Organization $organization): CarbonImmutable
    {
        if ($payment->paid_on !== null) {
            return CarbonImmutable::parse($payment->paid_on->toDateString(), $organization->timezone);
        }

        return CarbonImmutable::instance($payment->settled_at)->setTimezone($organization->timezone)->startOfDay();
    }

    private function rateOn(Organization $organization, CarbonImmutable $day): ?RateSnapshot
    {
        $key = $organization->getKey().':'.$day->toDateString();

        if (! array_key_exists($key, $this->rates)) {
            $this->rates[$key] = $this->service->on($organization, $day, RateSource::Parallel);
        }

        return $this->rates[$key];
    }
}
