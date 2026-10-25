<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use Throwable;

class PlatformMoney
{
    private ?RateSnapshot $rate = null;

    private bool $loaded = false;

    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * @return array{cents: int, usd: string, ves: string|null}
     */
    public function view(int $cents): array
    {
        $amount = new Money($cents);
        $rate = $this->rate();

        return [
            'cents' => $cents,
            'usd' => MoneyFormatter::format($amount),
            'ves' => $rate === null ? null : MoneyFormatter::format($amount->convertTo(Currency::VES, $rate)),
        ];
    }

    public function rate(): ?RateSnapshot
    {
        if (! $this->loaded) {
            $this->loaded = true;

            try {
                $this->rate = $this->rates->forSource($this->rates->platformSource());
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->rate;
    }
}
