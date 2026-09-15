<?php

declare(strict_types=1);

use App\Domain\Settings\SettingKey;

/**
 * Los rangos de cada clave, ejercitados contra el validador de verdad
 * (`REQ-ORG-006` RB-2, SP-1).
 *
 * Van en `Feature` y no junto al resto de `SettingKeyTest` por un motivo
 * mecánico: las reglas son cadenas que solo significan algo cuando las
 * interpreta el validador de Laravel, y en `Unit` no hay aplicación arrancada
 * que lo provea. Comprobar que la regla *dice* `max:90` no es comprobar que
 * rechaza 91.
 */
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

/**
 * **El default de cada clave pasa su propia validación.** Parece obvio y es el
 * caso que más caro sale: un default fuera de su rango se siembra en el
 * registro sin validarse, y el fallo aparece la primera vez que alguien abre
 * ajustes y pulsa guardar sin haber tocado nada.
 */
it('siembra defaults que pasan su propia validación', function (SettingKey $key): void {
    $validator = validator([$key->value => $key->defaultValue()], rulesFor($key));

    expect($validator->fails())->toBeFalse($key->value.' se siembra con un valor que su propia regla rechaza.');
})->with(SettingKey::cases());

/**
 * Cada clave con un valor dentro y otro fuera, que es lo que pide el gate de
 * `REQ-ORG-006`. Los topes no son adorno: cada uno acota un daño concreto y
 * está justificado en `SettingKey::validationRules()`.
 */
it('acepta lo que está en rango y rechaza lo que no', function (SettingKey $key, mixed $value, bool $valid): void {
    $validator = validator([$key->value => $value], rulesFor($key));

    expect($validator->fails())->toBe(! $valid);
})->with([
    // Fuente de la tasa: la lista es cerrada (`REQ-FX-003` RB-1).
    'fuente válida' => [SettingKey::ExchangeRateSource, 'parallel', true],
    'fuente inventada' => [SettingKey::ExchangeRateSource, 'binance', false],

    // Tasa manual: nula sí —es lo que vale sin fuente manual—, cero nunca
    // (`REQ-FX-003` SP-2).
    'tasa nula' => [SettingKey::ExchangeRateManual, null, true],
    'tasa positiva' => [SettingKey::ExchangeRateManual, '42.50', true],
    'tasa cero' => [SettingKey::ExchangeRateManual, 0, false],
    'tasa negativa' => [SettingKey::ExchangeRateManual, -1, false],
    'tasa que no es número' => [SettingKey::ExchangeRateManual, 'muchos bolívares', false],

    'moneda válida' => [SettingKey::CurrencyDisplayDefault, 'ves_primary', true],
    'moneda inventada' => [SettingKey::CurrencyDisplayDefault, 'eur_primary', false],

    // Sin gracia es un ajuste legítimo; un mes de gracia deja «vencida» sin
    // significado.
    'gracia de cero días' => [SettingKey::GracePeriodDays, 0, true],
    'gracia de un mes' => [SettingKey::GracePeriodDays, 30, true],
    'gracia de dos meses' => [SettingKey::GracePeriodDays, 60, false],
    'gracia negativa' => [SettingKey::GracePeriodDays, -1, false],

    // La ventana mantiene stock reservado sin haber cobrado nada.
    'ventana de una semana' => [SettingKey::DownPaymentWindowHours, 168, true],
    'ventana de un mes' => [SettingKey::DownPaymentWindowHours, 720, false],
    'ventana de cero horas' => [SettingKey::DownPaymentWindowHours, 0, false],

    // La tolerancia es para las migajas del redondeo, no para perdonar deuda.
    'tolerancia de diez dólares' => [SettingKey::SettlementToleranceCents, 1000, true],
    'tolerancia de cien dólares' => [SettingKey::SettlementToleranceCents, 10000, false],

    // Rangos normativos de `REQ-LAY-004` RB-7.
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

/**
 * Un offset repetido son **dos avisos el mismo día al mismo cliente**
 * (`REQ-NOT-002`), así que la lista exige elementos distintos.
 */
it('rechaza recordatorios repetidos', function (): void {
    $validator = validator(
        ['reminder_offsets_days' => [0, 0, 3]],
        rulesFor(SettingKey::ReminderOffsetsDays),
    );

    expect($validator->fails())->toBeTrue();
});

/**
 * Y el tope de diez: los recordatorios son correos a un cliente que no pidió
 * ninguno, y sin tope la lista se convierte en correo basura con nuestro
 * remitente.
 */
it('acota cuántos recordatorios se pueden programar', function (): void {
    $validator = validator(
        ['reminder_offsets_days' => range(1, 11)],
        rulesFor(SettingKey::ReminderOffsetsDays),
    );

    expect($validator->fails())->toBeTrue();
});
