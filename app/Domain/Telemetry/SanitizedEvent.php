<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

use Carbon\CarbonImmutable;

final readonly class SanitizedEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public TelemetryEventName $name,
        public ?int $organizationId,
        public ?int $catalogId,
        public ?string $subjectType,
        public ?int $subjectId,
        public array $payload,
        public CarbonImmutable $occurredAt,
    ) {}
}
