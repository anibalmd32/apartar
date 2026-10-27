<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Currency;

final readonly class RateDeviation
{
    public const MAX_PERCENT = 10;

    private function __construct(
        public ManualRate $manual,
        public RateSnapshot $reference,
        public int $percent,
    ) {}

    public static function in(RateSnapshot|ManualRate|null $rate, ?RateSnapshot $reference, Currency $currency): ?self
    {
        if ($currency === Currency::USD || ! $rate instanceof ManualRate || $reference === null || bccomp($reference->rate, '0', 8) <= 0) {
            return null;
        }

        $ratio = bcdiv(bcmul(bcsub($rate->rate, $reference->rate, 8), '100', 8), $reference->rate, 8);
        $percent = ltrim($ratio, '-');

        return bccomp($percent, (string) self::MAX_PERCENT, 8) > 0 ? new self($rate, $reference, (int) round((float) $percent)) : null;
    }

    public function token(): string
    {
        return sprintf('%s@%s', $this->manual->rate, $this->reference->rate);
    }

    /**
     * @throws UnconfirmedRateDeviationException
     */
    public function assertConfirmedBy(?string $token): void
    {
        if ($token !== $this->token()) {
            throw new UnconfirmedRateDeviationException($this);
        }
    }

    public function isAbove(): bool
    {
        return bccomp($this->manual->rate, $this->reference->rate, 8) > 0;
    }

    /**
     * @return array{token: string, percent: int, above: bool, manual: string, reference: string, sourceLabel: string}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token(),
            'percent' => $this->percent,
            'above' => $this->isAbove(),
            'manual' => self::display($this->manual->rate),
            'reference' => self::display($this->reference->rate),
            'sourceLabel' => $this->reference->source->label(),
        ];
    }

    public static function display(string $rate): string
    {
        return number_format((float) $rate, 2, ',', '.');
    }
}
