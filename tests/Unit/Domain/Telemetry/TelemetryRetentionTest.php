<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telemetry;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Telemetry\TelemetryRetention;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class TelemetryRetentionTest extends TestCase
{
    public function test_the_plan_decides_and_null_is_capped(): void
    {
        $this->assertSame(180, TelemetryRetention::daysFor($this->plan(180)));
        $this->assertSame(TelemetryRetention::UNCAPPED_DAYS, TelemetryRetention::daysFor($this->plan(null)));
        $this->assertSame(TelemetryRetention::WITHOUT_PLAN_DAYS, TelemetryRetention::daysFor(null));
    }

    public function test_the_cutoff_is_that_many_days_ago(): void
    {
        $now = CarbonImmutable::parse('2026-10-22 03:45:00');

        $this->assertSame('2026-09-22 03:45:00', TelemetryRetention::cutoff(30, $now)->format('Y-m-d H:i:s'));
    }

    private function plan(?int $days): Plan
    {
        return (new Plan)->forceFill(['features' => [PlanAllowance::TelemetryRetentionDays->value => $days]]);
    }
}
