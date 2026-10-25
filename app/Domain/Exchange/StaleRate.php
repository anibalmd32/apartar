<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Currency;
use Carbon\CarbonImmutable;

final readonly class StaleRate
{
    public const MAX_AGE_DAYS = 30;

    private function __construct(
        public RateSnapshot $snapshot,
    ) {}

    public static function in(RateSnapshot|ManualRate|null $rate, Currency $currency): ?self
    {
        if ($currency === Currency::USD || ! $rate instanceof RateSnapshot) {
            return null;
        }

        return $rate->publishedAt->addDays(self::MAX_AGE_DAYS)->isPast() ? new self($rate) : null;
    }

    public function token(): string
    {
        return sprintf('%s@%s@%d', $this->snapshot->source->value, $this->snapshot->rate, $this->snapshot->publishedAt->getTimestamp());
    }

    public function ageInDays(): int
    {
        return (int) $this->snapshot->publishedAt->diffInDays(CarbonImmutable::now());
    }

    /**
     * @throws UnconfirmedStaleRateException
     */
    public function assertConfirmedBy(?string $token): void
    {
        if ($token !== $this->token()) {
            throw new UnconfirmedStaleRateException($this);
        }
    }

    /**
     * @return array{token: string, days: int, sourceLabel: string}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token(),
            'days' => $this->ageInDays(),
            'sourceLabel' => $this->snapshot->source->label(),
        ];
    }
}
