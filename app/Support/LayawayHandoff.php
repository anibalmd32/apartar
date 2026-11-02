<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

final class LayawayHandoff
{
    public const SESSION_KEY = 'layaways.handoff';

    public const MINUTES = 30;

    public static function grant(Session $session, string $code): void
    {
        $pending = self::pending($session);
        $pending[$code] = CarbonImmutable::now()->getTimestamp();

        $session->put(self::SESSION_KEY, $pending);
    }

    public static function take(Session $session, string $code): bool
    {
        $pending = self::pending($session);
        $grantedAt = $pending[$code] ?? null;

        unset($pending[$code]);
        $session->put(self::SESSION_KEY, $pending);

        return $grantedAt !== null
            && CarbonImmutable::now()->getTimestamp() - $grantedAt <= self::MINUTES * 60;
    }

    /**
     * @return array<string, int>
     */
    private static function pending(Session $session): array
    {
        $stored = $session->get(self::SESSION_KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        return array_filter(
            $stored,
            fn (mixed $grantedAt, mixed $code): bool => is_string($code) && is_int($grantedAt),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
