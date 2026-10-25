<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use Carbon\CarbonImmutable;

final readonly class RateSnapshot
{
    public function __construct(
        public string $rate,
        public RateSource $source,
        public CarbonImmutable $publishedAt,
        public CarbonImmutable $fetchedAt,
    ) {}

    public function isStale(int $maxAgeHours = 24): bool
    {
        return $this->publishedAt->addHours($maxAgeHours)->isPast();
    }
}
