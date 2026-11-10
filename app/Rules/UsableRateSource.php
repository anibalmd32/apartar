<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Support\Settings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class UsableRateSource implements ValidationRule
{
    public const NO_MANUAL_RATE = 'Para usar tu tasa propia, escríbela primero en Ajustes, en la sección de la tasa.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $source = is_string($value) ? RateSource::tryFrom($value) : null;

        if ($source === null) {
            $fail('Elige una de las tasas de la lista.');

            return;
        }

        if ($source === RateSource::Manual && ! self::hasManualRate()) {
            $fail(self::NO_MANUAL_RATE);
        }
    }

    public static function hasManualRate(): bool
    {
        $manual = app(Settings::class)->get(SettingKey::ExchangeRateManual);

        return is_numeric($manual) && (float) $manual > 0;
    }
}
