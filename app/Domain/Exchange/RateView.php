<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

final readonly class RateView
{
    private function __construct(
        public ?float $rate,
        public RateSource $source,
        public ?string $publishedAt,
        public bool $isStale,
    ) {}

    public static function of(?RateSnapshot $snapshot, RateSource $source): self
    {
        if ($snapshot === null) {
            return new self(null, $source, null, false);
        }

        return new self(
            rate: (float) $snapshot->rate,
            source: $snapshot->source,
            publishedAt: $snapshot->publishedAt->toIso8601String(),
            isStale: $snapshot->isStale(),
        );
    }

    /**
     * @return array{rate: float|null, source: string, sourceLabel: string, publishedAt: string|null, isStale: bool}
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'source' => $this->source->value,
            'sourceLabel' => $this->source->label(),
            'publishedAt' => $this->publishedAt,
            'isStale' => $this->isStale,
        ];
    }
}
