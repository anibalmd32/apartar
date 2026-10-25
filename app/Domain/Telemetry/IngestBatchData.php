<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

use Illuminate\Support\Str;

final readonly class IngestBatchData
{
    public const MAX_EVENTS = 50;

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public string $visitorId,
        public string $sessionId,
        public array $events,
        public string $ipHash,
        public ?string $userAgent,
    ) {}

    public static function fromBody(mixed $body, ?string $ip, ?string $userAgent): ?self
    {
        if (! is_array($body)) {
            return null;
        }

        $visitorId = $body['visitorId'] ?? null;
        $sessionId = $body['sessionId'] ?? null;
        $events = $body['events'] ?? null;

        if (! is_string($visitorId) || ! Str::isUuid($visitorId)) {
            return null;
        }

        if (! is_string($sessionId) || preg_match('/^[A-Za-z0-9-]{8,40}$/', $sessionId) !== 1) {
            return null;
        }

        if (! is_array($events) || ! array_is_list($events) || $events === [] || count($events) > self::MAX_EVENTS) {
            return null;
        }

        return new self(
            visitorId: strtolower($visitorId),
            sessionId: $sessionId,
            events: array_values(array_filter($events, 'is_array')),
            ipHash: IpHasher::hash($ip),
            userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        );
    }
}
