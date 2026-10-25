<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class UpdateExchangeRateSettingsAction
{
    public function __construct(private readonly UpdateSettingsAction $settings) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(RateSource $source, ?string $manualRate, User $actor): array
    {
        return $this->settings->handle($this->payloadFor($source, $manualRate), $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(RateSource $source, ?string $manualRate): array
    {
        $payload = [SettingKey::ExchangeRateSource->value => $source->value];

        if ($source === RateSource::Manual || $manualRate !== null) {
            $payload[SettingKey::ExchangeRateManual->value] = $manualRate;
        }

        return $payload;
    }
}
