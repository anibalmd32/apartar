<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Settings\SettingKey;
use App\Models\Organization;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

final readonly class DueCalendar
{
    public function __construct(public CarbonImmutable $today, public int $soonDays) {}

    public static function of(Organization $organization, Settings $settings): self
    {
        $offsets = $settings->forOrganization($organization)[SettingKey::ReminderOffsetsDays->value] ?? SettingKey::ReminderOffsetsDays->defaultValue();

        return new self(
            CarbonImmutable::now(new DateTimeZone($organization->timezone))->startOfDay(),
            self::soonDaysFrom($offsets),
        );
    }

    public static function soonDaysFrom(mixed $offsets): int
    {
        $before = is_array($offsets)
            ? array_filter(array_map(fn (mixed $offset): int => is_numeric($offset) ? (int) $offset : 0, $offsets), fn (int $offset): bool => $offset < 0)
            : [];

        return $before === [] ? 0 : abs(min($before));
    }

    public function daysUntil(CarbonInterface $dueDate): int
    {
        return (int) round($this->today->diffInDays($this->day($dueDate), false));
    }

    public function isPastDue(CarbonInterface $dueDate): bool
    {
        return $this->daysUntil($dueDate) < 0;
    }

    public function isDueSoon(CarbonInterface $dueDate): bool
    {
        $days = $this->daysUntil($dueDate);

        return $days >= 0 && $days <= $this->soonDays;
    }

    public function lastSoonDay(): CarbonImmutable
    {
        return $this->today->addDays($this->soonDays);
    }

    public function day(CarbonInterface $dueDate): CarbonImmutable
    {
        return CarbonImmutable::parse($dueDate->toDateString(), $this->today->getTimezone())->startOfDay();
    }
}
