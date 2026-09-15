<?php

declare(strict_types=1);

use App\Domain\Settings\SettingKey;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\UnknownSettingException;

/**
 * Las diez claves de [02 §9.1] con sus valores por defecto, sus rangos y su
 * alcance.
 *
 * Los valores están escritos otra vez aquí, a mano: son **dos transcripciones
 * independientes** de la misma tabla de la especificación. Un test que leyera
 * los defaults del propio enum comprobaría que el enum se parece a sí mismo, y
 * pasaría en verde con un plazo de gracia de 30 días donde la spec dice 3.
 */
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

/**
 * `storefront_enabled` **no es un ajuste**: es la columna
 * `organizations.storefront_enabled` ([02 §9.1]). Estuvo declarado en los dos
 * sitios hasta que `T-010` lo detectó, y manda la columna porque la vitrina la
 * consulta en cada petición anónima y porque encender la vitrina es identidad
 * del negocio, no un parámetro de operación.
 */
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
    [SettingKey::OverdueAlertDays, 30],
]);

/**
 * El mapa completo, que es lo que consume el registro. Diez claves, ni una
 * menos: un ajuste que no se siembra obliga a que cada lectura conozca su
 * default, y ahí nace la segunda fuente de verdad.
 */
it('entrega las diez claves con su valor para sembrarlas de una vez', function (): void {
    $defaults = SettingKey::defaults();

    expect($defaults)->toHaveCount(10);
    expect($defaults['grace_period_days'])->toBe(3);
    expect($defaults['reminder_offsets_days'])->toBe([-3, 0, 1, 7, 15]);
    expect($defaults)->toHaveKey('exchange_rate_manual');
    expect($defaults['exchange_rate_manual'])->toBeNull();
    expect($defaults)->not->toHaveKey('storefront_enabled');
});

/**
 * Los tipos importan tanto como los números: un `3` que volviera como `"3"`
 * rompería las comparaciones de quien lo lea.
 */
it('conserva el tipo de cada valor', function (): void {
    expect(SettingKey::GracePeriodDays->defaultValue())->toBeInt();
    expect(SettingKey::ExchangeRateSource->defaultValue())->toBeString();
    expect(SettingKey::ReminderOffsetsDays->defaultValue())->toBeArray();
    expect(SettingKey::ExchangeRateManual->defaultValue())->toBeNull();
});

/**
 * RB-4 · cada clave declara a qué alcanza su cambio. Las tres que se congelan
 * al crear son las que la spec nombra: la ventana del inicial no mueve un
 * `expires_at` ya fijado, y la frecuencia y el número de cuotas viajan en el
 * *snapshot* del apartado.
 */
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

/**
 * Las dos puertas de RB-2: la silenciosa para lo que llega de fuera y la
 * ruidosa para lo que nombra nuestro propio código.
 */
it('ignora en silencio una clave desconocida y lanza cuando la nombramos nosotros', function (): void {
    expect(SettingKey::tryFromKey('lo_que_sea'))->toBeNull();

    expect(fn () => SettingKey::fromKey('lo_que_sea'))
        ->toThrow(UnknownSettingException::class);
});

it('resuelve una clave conocida por cualquiera de las dos puertas', function (): void {
    expect(SettingKey::tryFromKey('grace_period_days'))->toBe(SettingKey::GracePeriodDays);
    expect(SettingKey::fromKey('grace_period_days'))->toBe(SettingKey::GracePeriodDays);
});

/**
 * Toda clave tiene nombre para la pantalla: uno vacío dejaría un campo sin
 * etiqueta, que es un campo que nadie sabe rellenar.
 */
it('nombra cada clave para la pantalla', function (SettingKey $key): void {
    expect($key->label())->not->toBe('');
    expect($key->label())->not->toBe($key->value);
})->with(SettingKey::cases());
