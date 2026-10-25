<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

use App\Domain\Platform\PlatformSettingKey;
use App\Domain\Subscription\PlanAllowance;
use App\Models\Plan;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;

final class TelemetryRetention
{
    public const PLATFORM_DEFAULT_DAYS = 365;

    public const WITHOUT_PLAN_DAYS = 30;

    public const UNCAPPED_DAYS = 365;

    public static function platformDefaultDays(): int
    {
        return PlatformSettings::get(PlatformSettingKey::TelemetryRetentionDaysDefault);
    }

    public static function daysFor(?Plan $plan): int
    {
        if ($plan === null) {
            return self::WITHOUT_PLAN_DAYS;
        }

        return $plan->allowance(PlanAllowance::TelemetryRetentionDays) ?? self::UNCAPPED_DAYS;
    }

    public static function cutoff(int $days, CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays($days);
    }
}
