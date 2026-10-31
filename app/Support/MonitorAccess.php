<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class MonitorAccess
{
    public const SESSION_KEY = 'monitor.granted';

    public static function configured(): bool
    {
        return self::token() !== null;
    }

    public static function matches(string $candidate): bool
    {
        $token = self::token();

        return $token !== null && hash_equals($token, $candidate);
    }

    public static function grant(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, self::fingerprint());
    }

    public static function revoke(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate();
    }

    public static function granted(Request $request): bool
    {
        if (! self::configured() || ! $request->hasSession()) {
            return false;
        }

        $stored = $request->session()->get(self::SESSION_KEY);

        return is_string($stored) && hash_equals(self::fingerprint(), $stored);
    }

    private static function fingerprint(): string
    {
        return hash_hmac('sha256', 'monitor', (string) self::token());
    }

    private static function token(): ?string
    {
        $token = config('monitor.token');

        return is_string($token) && strlen($token) >= 32 ? $token : null;
    }
}
