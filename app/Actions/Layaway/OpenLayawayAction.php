<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\ScheduleAlreadyMaterializedException;
use App\Domain\Settings\SettingKey;
use App\Models\Layaway;
use App\Support\Settings;
use Carbon\CarbonImmutable;

final class OpenLayawayAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ActivateLayawayAction $activation,
    ) {}

    public function expiresAt(LayawayTerms $terms): ?CarbonImmutable
    {
        if (! $terms->requiresDownPayment()) {
            return null;
        }

        return CarbonImmutable::now()->addHours($this->downPaymentWindowHours());
    }

    /**
     * @throws IllegalLayawayTransitionException
     * @throws ScheduleAlreadyMaterializedException
     */
    public function handle(Layaway $layaway): void
    {
        if ($layaway->terms()->requiresDownPayment()) {
            return;
        }

        $this->activation->handle($layaway);
    }

    private function downPaymentWindowHours(): int
    {
        $hours = $this->settings->get(SettingKey::DownPaymentWindowHours);

        return is_numeric($hours) ? (int) $hours : (int) SettingKey::DownPaymentWindowHours->defaultValue();
    }
}
