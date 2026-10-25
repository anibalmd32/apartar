<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

use Illuminate\Support\Facades\Cache;

final class VisitorIdentity
{
    public const TTL_SECONDS = 7200;

    /**
     * @param  array<int, int>  $customers
     */
    public function __construct(
        public readonly ?int $accountId = null,
        public readonly array $customers = [],
    ) {}

    public static function of(string $visitorId): self
    {
        $stored = Cache::get(self::key($visitorId));

        if (! is_array($stored)) {
            return new self;
        }

        return new self(
            accountId: is_int($stored['account'] ?? null) ? $stored['account'] : null,
            customers: is_array($stored['customers'] ?? null) ? array_filter($stored['customers'], 'is_int') : [],
        );
    }

    public static function remember(string $visitorId, ?int $accountId, ?int $organizationId = null, ?int $customerId = null): void
    {
        $current = self::of($visitorId);
        $customers = $current->customers;

        if ($organizationId !== null && $customerId !== null) {
            $customers[$organizationId] = $customerId;
        }

        $accountId ??= $current->accountId;

        if ($accountId === $current->accountId && $customers === $current->customers && Cache::has(self::key($visitorId))) {
            return;
        }

        Cache::put(self::key($visitorId), ['account' => $accountId, 'customers' => $customers], self::TTL_SECONDS);
    }

    public function customerIn(?int $organizationId): ?int
    {
        return $organizationId === null ? null : ($this->customers[$organizationId] ?? null);
    }

    private static function key(string $visitorId): string
    {
        return 'telemetry:visitor:'.$visitorId;
    }
}
