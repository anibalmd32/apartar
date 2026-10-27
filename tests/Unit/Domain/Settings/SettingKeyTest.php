<?php

declare(strict_types=1);

use App\Domain\Settings\SettingKey;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\UnknownSettingException;

it('declara exactamente las diez claves de §9.1', function (): void {
    $values = array_map(fn (SettingKey $key): string => $key->value, SettingKey::cases());

    expect($values)->toBe([
        'exchange_rate_source',
        'exchange_rate_manual',
        'currency_display_default',
        'grace_period_days',
        'down_payment_window_hours',
        'settlement_tolerance_cents',
        'default_frequency_days',
        'default_installments_count',
        'reminder_offsets_days',
        'overdue_alert_days',
    ]);
});

it('no declara la vitrina, que es una columna y no un ajuste', function (): void {
    expect(SettingKey::tryFromKey('storefront_enabled'))->toBeNull();
});

it('siembra el valor por defecto que fija §9.1', function (SettingKey $key, mixed $expected): void {
    expect($key->defaultValue())->toBe($expected);
})->with([
    [SettingKey::ExchangeRateSource, 'bcv'],
    [SettingKey::ExchangeRateManual, null],
    [SettingKey::CurrencyDisplayDefault, 'usd_primary'],
    [SettingKey::GracePeriodDays, 3],
    [SettingKey::DownPaymentWindowHours, 72],
    [SettingKey::SettlementToleranceCents, 50],
    [SettingKey::DefaultFrequencyDays, 15],
    [SettingKey::DefaultInstallmentsCount, 4],
    [SettingKey::ReminderOffsetsDays, [-3, 0, 1, 7, 15]],
    [SettingKey::OverdueAlertDays, 15],
]);

it('entrega las diez claves con su valor para sembrarlas de una vez', function (): void {
    $defaults = SettingKey::defaults();

    expect($defaults)->toHaveCount(10);
    expect($defaults['grace_period_days'])->toBe(3);
    expect($defaults['reminder_offsets_days'])->toBe([-3, 0, 1, 7, 15]);
    expect($defaults)->toHaveKey('exchange_rate_manual');
    expect($defaults['exchange_rate_manual'])->toBeNull();
    expect($defaults)->not->toHaveKey('storefront_enabled');
});

it('conserva el tipo de cada valor', function (): void {
    expect(SettingKey::GracePeriodDays->defaultValue())->toBeInt();
    expect(SettingKey::ExchangeRateSource->defaultValue())->toBeString();
    expect(SettingKey::ReminderOffsetsDays->defaultValue())->toBeArray();
    expect(SettingKey::ExchangeRateManual->defaultValue())->toBeNull();
});

it('declara el alcance de cada clave', function (SettingKey $key, SettingScope $expected): void {
    expect($key->scope())->toBe($expected);
})->with([
    [SettingKey::DownPaymentWindowHours, SettingScope::FutureOnly],
    [SettingKey::DefaultFrequencyDays, SettingScope::FutureOnly],
    [SettingKey::DefaultInstallmentsCount, SettingScope::FutureOnly],
    [SettingKey::GracePeriodDays, SettingScope::Daily],
    [SettingKey::ReminderOffsetsDays, SettingScope::Daily],
    [SettingKey::OverdueAlertDays, SettingScope::Daily],
    [SettingKey::ExchangeRateSource, SettingScope::Immediate],
    [SettingKey::CurrencyDisplayDefault, SettingScope::Immediate],
]);

it('ignora en silencio una clave desconocida y lanza cuando la nombramos nosotros', function (): void {
    expect(SettingKey::tryFromKey('lo_que_sea'))->toBeNull();

    expect(fn () => SettingKey::fromKey('lo_que_sea'))
        ->toThrow(UnknownSettingException::class);
});

it('resuelve una clave conocida por cualquiera de las dos puertas', function (): void {
    expect(SettingKey::tryFromKey('grace_period_days'))->toBe(SettingKey::GracePeriodDays);
    expect(SettingKey::fromKey('grace_period_days'))->toBe(SettingKey::GracePeriodDays);
});

it('nombra cada clave para la pantalla', function (SettingKey $key): void {
    expect($key->label())->not->toBe('');
    expect($key->label())->not->toBe($key->value);
})->with(SettingKey::cases());
