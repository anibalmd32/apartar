<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class LoginLinkToken
{
    public const TTL_MINUTES = 30;

    private const SEPARATOR = '.';

    private const SECRET_BYTES = 32;

    private function __construct(
        public string $plainToken,
        public string $tokenHash,
        public CarbonImmutable $expiresAt,
    ) {}

    public static function issueFor(Organization $organization): self
    {
        $plainToken = $organization->getKey().self::SEPARATOR.bin2hex(random_bytes(self::SECRET_BYTES));

        return new self($plainToken, self::hash($plainToken), CarbonImmutable::now()->addMinutes(self::TTL_MINUTES));
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function organizationIdIn(string $plainToken): ?int
    {
        $prefix = Str::before($plainToken, self::SEPARATOR);

        return $prefix !== '' && $prefix !== $plainToken && ctype_digit($prefix) ? (int) $prefix : null;
    }

    public function url(): string
    {
        return route('portal.link.show', ['token' => $this->plainToken]);
    }
}
