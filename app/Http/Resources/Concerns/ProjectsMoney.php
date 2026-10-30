<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;

trait ProjectsMoney
{
    /**
     * @return array{cents: int, usd: string, ves: string|null}
     */
    protected function money(Money $amount): array
    {
        return [
            'cents' => $amount->cents,
            'usd' => MoneyFormatter::format($amount),
            'ves' => $this->projectedToVes($amount),
        ];
    }

    /**
     * @param  list<array{organization: Organization, amount: Money}>  $parts
     * @return array{cents: int, usd: string, ves: string|null}
     */
    protected function moneyAcrossBusinesses(array $parts): array
    {
        $usd = new Money(0);
        $ves = new Money(0, Currency::VES);

        foreach ($parts as $part) {
            $usd = $usd->add($part['amount']);
            $rate = app(TenantContext::class)->runFor($part['organization'], fn (): ?RateSnapshot => $this->currentRate()->snapshot());
            $ves = $rate === null || $ves === null ? null : $ves->add($part['amount']->convertTo(Currency::VES, $rate));
        }

        return [
            'cents' => $usd->cents,
            'usd' => MoneyFormatter::format($usd),
            'ves' => $ves === null ? null : MoneyFormatter::format($ves),
        ];
    }

    /**
     * @return array{paid: array{cents: int, usd: string, ves: string|null}, missing: array{cents: int, usd: string, ves: string|null}}|null
     */
    protected function downPaymentProgressOf(Layaway $layaway): ?array
    {
        $total = $layaway->terms()->totalDownPayment()->cents;
        $paid = min($total, $layaway->paid_cents->cents);

        return $layaway->status === LayawayStatus::PendingDownPayment && $paid > 0 ? [
            'paid' => $this->money(new Money($paid)),
            'missing' => $this->money(new Money($total - $paid)),
        ] : null;
    }

    private function projectedToVes(Money $amount): ?string
    {
        $rate = $this->currentRate()->snapshot();

        if ($rate === null) {
            return null;
        }

        return MoneyFormatter::format($amount->convertTo(Currency::VES, $rate));
    }

    private function currentRate(): CurrentRate
    {
        return app(CurrentRate::class);
    }
}
