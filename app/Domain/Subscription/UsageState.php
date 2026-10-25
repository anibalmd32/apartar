<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

final readonly class UsageState
{
    private function __construct(
        public UsageMetric $metric,
        public int $used,
        public ?int $limit,
        public bool $isAtLimit,
        public ?int $percentage,
    ) {}

    public static function of(UsageMetric $metric, int $used, ?int $limit): self
    {
        return new self(
            metric: $metric,
            used: $used,
            limit: $limit,
            isAtLimit: $limit !== null && $used >= $limit,
            percentage: self::percentageOf($used, $limit),
        );
    }

    public function admits(int $amount): bool
    {
        return $this->limit === null || $this->used + $amount <= $this->limit;
    }

    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    public function deservesWarning(): bool
    {
        return $this->percentage !== null && $this->percentage >= 80;
    }

    /**
     * @return array{used: int, limit: int|null, isAtLimit: bool, percentage: int|null}
     */
    public function toArray(): array
    {
        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'isAtLimit' => $this->isAtLimit,
            'percentage' => $this->percentage,
        ];
    }

    private static function percentageOf(int $used, ?int $limit): ?int
    {
        if ($limit === null || $limit <= 0) {
            return null;
        }

        return (int) min(100, round($used / $limit * 100));
    }
}
