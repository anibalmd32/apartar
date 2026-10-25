<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Models\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

final readonly class FollowIntent
{
    public const SESSION_KEY = 'account.follow_intent';

    public const TTL_MINUTES = 15;

    public function __construct(
        public int $catalogId,
        public string $catalogSlug,
        public CarbonImmutable $expiresAt,
    ) {}

    public static function remember(Session $session, Catalog $catalog, CarbonImmutable $now): self
    {
        $intent = new self((int) $catalog->getKey(), $catalog->slug, $now->addMinutes(self::TTL_MINUTES));

        $session->put(self::SESSION_KEY, [
            'catalog_id' => $intent->catalogId,
            'catalog_slug' => $intent->catalogSlug,
            'expires_at' => $intent->expiresAt->toIso8601String(),
        ]);

        return $intent;
    }

    public static function pull(Session $session, CarbonImmutable $now): ?self
    {
        $stored = $session->pull(self::SESSION_KEY);

        if (! is_array($stored)
            || ! is_int($stored['catalog_id'] ?? null)
            || ! is_string($stored['catalog_slug'] ?? null)
            || ! is_string($stored['expires_at'] ?? null)) {
            return null;
        }

        $intent = new self($stored['catalog_id'], $stored['catalog_slug'], CarbonImmutable::parse($stored['expires_at']));

        return $intent->expiresAt->isAfter($now) ? $intent : null;
    }

    public function isFor(Catalog $catalog): bool
    {
        return (int) $catalog->getKey() === $this->catalogId;
    }
}
