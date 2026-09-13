<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * Las once claves de `organization_settings` con su valor por defecto
 * ([02 §9.1], `REQ-ORG-006`).
 *
 * El enum nace en `T-010` y no en `T-014`, que es quien gobierna los ajustes,
 * y el motivo es concreto: `RegisterOrganizationAction` tiene que sembrar los
 * valores por defecto **dentro de la transacción del registro**, y hacerlo
 * contra un array escrito a mano crearía una segunda fuente de verdad que se
 * separaría de la primera en cuanto alguien cambiara un default. `T-014` añade
 * lo demás —la clase `Settings` con su caché por organización, la validación
 * de rango por clave, la acción de actualizar y la página—.
 *
 * **Los defaults se siembran como filas y no se dejan implícitos.** Un ajuste
 * ausente obligaría a que cada lectura conociera el default, que es
 * exactamente la segunda fuente de verdad que esto evita; y con las filas
 * puestas, el `owner` ve en su pantalla de ajustes lo que de verdad está
 * rigiendo su negocio desde el primer día.
 */
enum SettingKey: string
{
    /** De dónde sale la tasa: `bcv`, `parallel` o `manual` (`REQ-FX-003`). */
    case ExchangeRateSource = 'exchange_rate_source';

    /** Tasa fija que escribe el negocio cuando la fuente es `manual`. */
    case ExchangeRateManual = 'exchange_rate_manual';

    /** Moneda que se muestra primero: `usd_primary` o `ves_primary` (`REQ-FX-004`). */
    case CurrencyDisplayDefault = 'currency_display_default';

    /** Días de gracia antes de marcar una cuota vencida (`REQ-LAY-007`). */
    case GracePeriodDays = 'grace_period_days';

    /** Horas para pagar el inicial antes de liberar la reserva (`REQ-LAY-006`). */
    case DownPaymentWindowHours = 'down_payment_window_hours';

    /** Céntimos de diferencia que se dan por saldados (`REQ-FX-005`). */
    case SettlementToleranceCents = 'settlement_tolerance_cents';

    /** Días entre cuotas de un apartado nuevo (`REQ-LAY-004`). */
    case DefaultFrequencyDays = 'default_frequency_days';

    /** Número de cuotas de un apartado nuevo (`REQ-BOF-020`). */
    case DefaultInstallmentsCount = 'default_installments_count';

    /** Días de recordatorio respecto al vencimiento (`REQ-NOT-002`). */
    case ReminderOffsetsDays = 'reminder_offsets_days';

    /** Días de retraso a partir de los que se alerta (`REQ-LAY-009`). */
    case OverdueAlertDays = 'overdue_alert_days';

    /** Vitrina `/{org-slug}` visible o no (`REQ-ORG-006`). */
    case StorefrontEnabled = 'storefront_enabled';

    /**
     * Valor por defecto de la clave, tal como lo fija [02 §9.1].
     *
     * El `match` es exhaustivo y sin `default`: una clave nueva sin valor
     * inicial decidido no compila, en lugar de sembrarse como `null` y
     * aparecer meses después como un ajuste vacío en la pantalla de alguien.
     */
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
            self::OverdueAlertDays => 30,
            self::StorefrontEnabled => false,
        };
    }

    /**
     * Las once claves con su valor por defecto, listas para sembrar.
     *
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
}
