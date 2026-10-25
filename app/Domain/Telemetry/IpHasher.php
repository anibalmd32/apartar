<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

final class IpHasher
{
    public static function hash(?string $ip): string
    {
        return hash_hmac('sha256', (string) $ip, (string) config('app.key'));
    }
}
