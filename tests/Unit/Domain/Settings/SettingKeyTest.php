<?php

declare(strict_types=1);

use App\Domain\Settings\SettingKey;

/**
 * Las once claves de [02 §9.1] con sus valores por defecto.
 *
 * Los valores están escritos otra vez aquí, a mano: son **dos
 * transcripciones independientes** de la misma tabla de la especificación. Un
 * test que leyera los defaults del propio enum comprobaría que el enum se
 * parece a sí mismo, y pasaría en verde con un plazo de gracia de 30 días
 * donde la spec dice 3.
 */
it('declara exactamente las once claves de §9.1', function (): void {
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
        'storefront_enabled',
    ]);
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
    [SettingKey::OverdueAlertDays, 30],
    [SettingKey::StorefrontEnabled, false],
]);

/**
 * El mapa completo, que es lo que consume el registro. Once claves, ni una
 * menos: un ajuste que no se siembra obliga a que cada lectura conozca su
 * default, y ahí nace la segunda fuente de verdad.
 */
it('entrega las once claves con su valor para sembrarlas de una vez', function (): void {
    $defaults = SettingKey::defaults();

    expect($defaults)->toHaveCount(11);
    expect($defaults['grace_period_days'])->toBe(3);
    expect($defaults['reminder_offsets_days'])->toBe([-3, 0, 1, 7, 15]);
    expect($defaults)->toHaveKey('exchange_rate_manual');
    expect($defaults['exchange_rate_manual'])->toBeNull();
});

/**
 * Los tipos importan tanto como los números: un `false` que volviera como
 * `"0"` o un `3` como `"3"` romperían las comparaciones de quien los lea.
 */
it('conserva el tipo de cada valor', function (): void {
    expect(SettingKey::StorefrontEnabled->defaultValue())->toBeBool();
    expect(SettingKey::GracePeriodDays->defaultValue())->toBeInt();
    expect(SettingKey::ExchangeRateSource->defaultValue())->toBeString();
    expect(SettingKey::ReminderOffsetsDays->defaultValue())->toBeArray();
});
