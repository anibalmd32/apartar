<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

final readonly class UsageDrift
{
    public function __construct(
        public int $organizationId,
        public UsageMetric $metric,
        public int $stored,
        public int $measured,
    ) {}

    public function isUnexpected(): bool
    {
        return ! ($this->metric->shrinksWithTime() && $this->measured < $this->stored);
    }

    /**
     * @return array{organization_id: int, metric: string, stored: int, measured: int}
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'metric' => $this->metric->value,
            'stored' => $this->stored,
            'measured' => $this->measured,
        ];
    }
}
