<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final readonly class TeamInvitation
{
    public const TTL_HOURS = 48;

    private const SEPARATOR = '.';

    private const SECRET_BYTES = 32;

    private function __construct(
        public string $plainToken,
        public string $tokenHash,
        public Carbon $expiresAt,
    ) {}

    public static function issueFor(Organization $organization): self
    {
        $plainToken = $organization->getKey().self::SEPARATOR.bin2hex(random_bytes(self::SECRET_BYTES));

        return new self(
            plainToken: $plainToken,
            tokenHash: self::hash($plainToken),
            expiresAt: Carbon::now()->addHours(self::TTL_HOURS),
        );
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function organizationIdIn(string $plainToken): ?int
    {
        $prefix = Str::before($plainToken, self::SEPARATOR);

        return $prefix !== '' && ctype_digit($prefix) ? (int) $prefix : null;
    }

    public function url(): string
    {
        return route('team.invitation.show', ['token' => $this->plainToken]);
    }
}
