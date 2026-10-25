<?php

declare(strict_types=1);

use App\Domain\Settings\SettingKey;

/**
 * @return array<string, list<mixed>>
 */
function rulesFor(SettingKey $key): array
{
    $rules = [$key->value => $key->validationRules()];

    $items = $key->itemValidationRules();

    if ($items !== null) {
        $rules[$key->value.'.*'] = $items;
    }

    return $rules;
}

it('siembra defaults que pasan su propia validación', function (SettingKey $key): void {
    $validator = validator([$key->value => $key->defaultValue()], rulesFor($key));

    expect($validator->fails())->toBeFalse($key->value.' se siembra con un valor que su propia regla rechaza.');
})->with(SettingKey::cases());

it('acepta lo que está en rango y rechaza lo que no', function (SettingKey $key, mixed $value, bool $valid): void {
    $validator = validator([$key->value => $value], rulesFor($key));

    expect($validator->fails())->toBe(! $valid);
})->with([
    'fuente válida' => [SettingKey::ExchangeRateSource, 'parallel', true],
    'fuente inventada' => [SettingKey::ExchangeRateSource, 'binance', false],

    'tasa nula' => [SettingKey::ExchangeRateManual, null, true],
    'tasa positiva' => [SettingKey::ExchangeRateManual, '42.50', true],
    'tasa cero' => [SettingKey::ExchangeRateManual, 0, false],
    'tasa negativa' => [SettingKey::ExchangeRateManual, -1, false],
    'tasa que no es número' => [SettingKey::ExchangeRateManual, 'muchos bolívares', false],

    'moneda válida' => [SettingKey::CurrencyDisplayDefault, 'ves_primary', true],
    'moneda inventada' => [SettingKey::CurrencyDisplayDefault, 'eur_primary', false],

    'gracia de cero días' => [SettingKey::GracePeriodDays, 0, true],
    'gracia de un mes' => [SettingKey::GracePeriodDays, 30, true],
    'gracia de dos meses' => [SettingKey::GracePeriodDays, 60, false],
    'gracia negativa' => [SettingKey::GracePeriodDays, -1, false],

    'ventana de una semana' => [SettingKey::DownPaymentWindowHours, 168, true],
    'ventana de un mes' => [SettingKey::DownPaymentWindowHours, 720, false],
    'ventana de cero horas' => [SettingKey::DownPaymentWindowHours, 0, false],

    'tolerancia de diez dólares' => [SettingKey::SettlementToleranceCents, 1000, true],
    'tolerancia de cien dólares' => [SettingKey::SettlementToleranceCents, 10000, false],

    'frecuencia de 90 días' => [SettingKey::DefaultFrequencyDays, 90, true],
    'frecuencia de 91 días' => [SettingKey::DefaultFrequencyDays, 91, false],
    'frecuencia de cero días' => [SettingKey::DefaultFrequencyDays, 0, false],
    'treinta y seis cuotas' => [SettingKey::DefaultInstallmentsCount, 36, true],
    'treinta y siete cuotas' => [SettingKey::DefaultInstallmentsCount, 37, false],

    'alerta al año' => [SettingKey::OverdueAlertDays, 365, true],
    'alerta a los dos años' => [SettingKey::OverdueAlertDays, 730, false],

    'recordatorios válidos' => [SettingKey::ReminderOffsetsDays, [-3, 0, 7], true],
    'recordatorios vacíos' => [SettingKey::ReminderOffsetsDays, [], false],
    'recordatorio fuera de rango' => [SettingKey::ReminderOffsetsDays, [400], false],
    'recordatorio que no es entero' => [SettingKey::ReminderOffsetsDays, ['mañana'], false],
]);

it('rechaza recordatorios repetidos', function (): void {
    $validator = validator(
        ['reminder_offsets_days' => [0, 0, 3]],
        rulesFor(SettingKey::ReminderOffsetsDays),
    );

    expect($validator->fails())->toBeTrue();
});

it('acota cuántos recordatorios se pueden programar', function (): void {
    $validator = validator(
        ['reminder_offsets_days' => range(1, 11)],
        rulesFor(SettingKey::ReminderOffsetsDays),
    );

    expect($validator->fails())->toBeTrue();
});
