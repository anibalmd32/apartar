<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telemetry;

use App\Domain\Telemetry\SanitizedEvent;
use App\Domain\Telemetry\TelemetryEventName;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SanitizedEventTest extends TestCase
{
    public function test_it_carries_what_the_server_derived(): void
    {
        $at = CarbonImmutable::parse('2026-10-21 10:00:00');
        $event = new SanitizedEvent(TelemetryEventName::ProductViewed, 3, 7, 'product', 11, ['source' => 'catalog'], $at);

        $this->assertSame(TelemetryEventName::ProductViewed, $event->name);
        $this->assertSame([3, 7, 'product', 11], [$event->organizationId, $event->catalogId, $event->subjectType, $event->subjectId]);
        $this->assertSame(['source' => 'catalog'], $event->payload);
        $this->assertSame($at, $event->occurredAt);
    }
}
