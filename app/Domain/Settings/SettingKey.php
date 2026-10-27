<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\CurrencyDisplay;

enum SettingKey: string
{
    case ExchangeRateSource = 'exchange_rate_source';

    case ExchangeRateManual = 'exchange_rate_manual';

    case CurrencyDisplayDefault = 'currency_display_default';

    case GracePeriodDays = 'grace_period_days';

    case DownPaymentWindowHours = 'down_payment_window_hours';

    case SettlementToleranceCents = 'settlement_tolerance_cents';

    case DefaultFrequencyDays = 'default_frequency_days';

    case DefaultInstallmentsCount = 'default_installments_count';

    case ReminderOffsetsDays = 'reminder_offsets_days';

    case OverdueAlertDays = 'overdue_alert_days';

    public function defaultValue(): mixed
    {
        return match ($this) {
            self::ExchangeRateSource => 'bcv',
            self::ExchangeRateManual => null,
            self::CurrencyDisplayDefault => 'usd_primary',
            self::GracePeriodDays => 3,
            self::DownPaymentWindowHours => 72,
            self::SettlementToleranceCents => 50,
            self::DefaultFrequencyDays => 15,
            self::DefaultInstallmentsCount => 4,
            self::ReminderOffsetsDays => [-3, 0, 1, 7, 15],
            self::OverdueAlertDays => 15,
        };
    }

    /**
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::ExchangeRateSource => ['required', 'string', 'in:'.implode(',', array_column(RateSource::cases(), 'value'))],

            self::ExchangeRateManual => ['present', 'nullable', 'numeric', 'gt:0', 'max:1000000'],

            self::CurrencyDisplayDefault => ['required', 'string', 'in:'.implode(',', CurrencyDisplay::values())],

            self::GracePeriodDays => ['required', 'integer', 'min:0', 'max:30'],
            self::DownPaymentWindowHours => ['required', 'integer', 'min:1', 'max:168'],
            self::SettlementToleranceCents => ['required', 'integer', 'min:0', 'max:1000'],
            self::DefaultFrequencyDays => ['required', 'integer', 'min:1', 'max:90'],
            self::DefaultInstallmentsCount => ['required', 'integer', 'min:1', 'max:36'],
            self::OverdueAlertDays => ['required', 'integer', 'min:1', 'max:365'],

            self::ReminderOffsetsDays => ['required', 'array', 'min:1', 'max:10'],
        };
    }

    /**
     * @return list<mixed>|null
     */
    public function itemValidationRules(): ?array
    {
        return match ($this) {
            self::ReminderOffsetsDays => ['integer', 'min:-30', 'max:90', 'distinct'],

            self::ExchangeRateSource, self::ExchangeRateManual, self::CurrencyDisplayDefault,
            self::GracePeriodDays, self::DownPaymentWindowHours, self::SettlementToleranceCents,
            self::DefaultFrequencyDays, self::DefaultInstallmentsCount, self::OverdueAlertDays => null,
        };
    }

    public function scope(): SettingScope
    {
        return match ($this) {
            self::GracePeriodDays, self::ReminderOffsetsDays, self::OverdueAlertDays => SettingScope::Daily,

            self::DownPaymentWindowHours, self::DefaultFrequencyDays,
            self::DefaultInstallmentsCount => SettingScope::FutureOnly,

            self::ExchangeRateSource, self::ExchangeRateManual,
            self::CurrencyDisplayDefault, self::SettlementToleranceCents => SettingScope::Immediate,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ExchangeRateSource => 'Fuente de la tasa',
            self::ExchangeRateManual => 'Tasa manual (Bs. por USD)',
            self::CurrencyDisplayDefault => 'Moneda que se muestra primero',
            self::GracePeriodDays => 'Días de gracia antes de marcar vencida una cuota',
            self::DownPaymentWindowHours => 'Horas para pagar el inicial',
            self::SettlementToleranceCents => 'Tolerancia de liquidación (céntimos)',
            self::DefaultFrequencyDays => 'Días entre cuotas por defecto',
            self::DefaultInstallmentsCount => 'Número de cuotas por defecto',
            self::ReminderOffsetsDays => 'Días de recordatorio respecto al vencimiento',
            self::OverdueAlertDays => 'Días de mora prolongada, contados después de la gracia',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::cases() as $key) {
            $defaults[$key->value] = $key->defaultValue();
        }

        return $defaults;
    }

    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom($key);
    }

    /**
     * @throws UnknownSettingException
     */
    public static function fromKey(string $key): self
    {
        return self::tryFrom($key) ?? throw UnknownSettingException::named($key);
    }
}
