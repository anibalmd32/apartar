<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Platform\PlatformSettingKey;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;

final readonly class DowngradeOutcome
{
    public const GRACE_DAYS = 7;

    public static function unpublishDateFor(CarbonImmutable $appliedAt): CarbonImmutable
    {
        return $appliedAt->addDays(self::graceDays());
    }

    public static function graceDays(): int
    {
        return PlatformSettings::get(PlatformSettingKey::DowngradeGraceDays);
    }

    /**
     * @param  list<string>  $unpublished
     */
    public function __construct(
        public ?string $fromPlan,
        public ?string $toPlan,
        public array $unpublished,
        public ?CarbonImmutable $unpublishAt,
    ) {}

    public static function nothing(): self
    {
        return new self(null, null, [], null);
    }

    public function applied(): bool
    {
        return $this->toPlan !== null;
    }

    public function isNoop(): bool
    {
        return ! $this->applied() && $this->unpublished === [];
    }
}
