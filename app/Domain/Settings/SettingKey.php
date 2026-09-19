<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Exchange\RateSource;

/**
 * Las diez claves de `organization_settings` con su valor por defecto, su
 * rango y su alcance ([02 §9.1], `REQ-ORG-006`).
 *
 * El enum nace en `T-010` y no en `T-014`, que es quien gobierna los ajustes,
 * y el motivo es concreto: `RegisterOrganizationAction` tiene que sembrar los
 * valores por defecto **dentro de la transacción del registro**, y hacerlo
 * contra un array escrito a mano crearía una segunda fuente de verdad que se
 * separaría de la primera en cuanto alguien cambiara un default.
 *
 * **Son diez y no once.** `storefront_enabled` estuvo aquí y en
 * `organizations.storefront_enabled` a la vez hasta que `T-010` lo detectó;
 * manda la columna ([02 §9.1]) porque la vitrina la consulta en **cada
 * petición anónima** —y una columna se filtra en la misma consulta que
 * resuelve la organización, sin pasar por la caché de ajustes— y porque
 * encender la vitrina es identidad del negocio, como el logo, no un parámetro
 * de operación como la tolerancia de liquidación. `T-014` retira la clave y
 * borra las filas que `T-010` llegó a sembrar.
 *
 * **Los defaults se siembran como filas y no se dejan implícitos.** Un ajuste
 * ausente obligaría a que cada lectura conociera el default, que es
 * exactamente la segunda fuente de verdad que esto evita; y con las filas
 * puestas, el `owner` ve en su pantalla lo que de verdad está rigiendo su
 * negocio desde el primer día.
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

    /**
     * Formas de presentar el precio (`REQ-FX-004`).
     *
     * @var list<string>
     */
    public const CURRENCY_DISPLAYS = ['usd_primary', 'ves_primary'];

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
        };
    }

    /**
     * Reglas con las que se valida el valor entrante (RB-2, SP-1).
     *
     * Los topes no son adorno: cada uno acota un daño concreto, y por eso van
     * aquí y no en un `FormRequest` —los usa también quien escriba ajustes
     * desde un comando o un seeder—.
     *
     *  - **`default_frequency_days` ∈ [1, 90] y `default_installments_count`
     *    ∈ [1, 36]** son de `REQ-LAY-004` RB-7, que ya los declara para el
     *    cronograma. Aceptar aquí un valor que el generador rechazará después
     *    sería guardar un default que revienta el primer apartado que lo use.
     *  - **`down_payment_window_hours` ≤ 168** (una semana): la ventana
     *    mantiene el stock reservado sin haber cobrado nada, así que un tope
     *    alto es inventario bloqueado por un cliente que no apareció.
     *  - **`grace_period_days` ≤ 30**: con más de un mes de gracia, «vencida»
     *    deja de significar nada y la cobranza no avisa de lo que hay que
     *    cobrar.
     *  - **`settlement_tolerance_cents` ≤ 1000** ($10): la tolerancia existe
     *    para las migajas del redondeo de tasa (`REQ-FX-005`), no para
     *    perdonar deuda. Sin tope, se convierte en un descuento silencioso que
     *    no aparece en ningún informe.
     *  - **`overdue_alert_days` ≤ 365**: más de un año de mora no es una
     *    alerta, es otra decisión.
     *  - **`reminder_offsets_days`**: enteros entre -30 y 90 y **distintos**,
     *    porque un offset repetido son dos avisos el mismo día al mismo
     *    cliente (`REQ-NOT-002`); el tope de diez evita convertir los
     *    recordatorios en correo basura.
     *
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        return match ($this) {
            // El censo de las tres fuentes es `RateSource`, y no se copia aquí
            // (`T-019`): con dos listas, la cuarta fuente deja corta a una de
            // ellas, y el síntoma no es un test en rojo sino un
            // `RateSource::from()` que lanza en producción sobre un ajuste
            // que esta misma validación dio por bueno.
            self::ExchangeRateSource => ['required', 'string', 'in:'.implode(',', array_column(RateSource::cases(), 'value'))],

            // SP-2 de `REQ-FX-003`: formato inválido o ≤ 0 es `422`. Nulo sí,
            // porque es lo que vale mientras la fuente no sea manual.
            self::ExchangeRateManual => ['present', 'nullable', 'numeric', 'gt:0', 'max:1000000'],

            self::CurrencyDisplayDefault => ['required', 'string', 'in:'.implode(',', self::CURRENCY_DISPLAYS)],

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
     * Reglas de cada elemento, para las claves que guardan una lista.
     *
     * Van aparte porque Laravel las valida con la notación `clave.*` y quien
     * compone el array de reglas necesita saber si hay que añadirla.
     *
     * @return list<mixed>|null `null` si la clave no es una lista
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

    /**
     * A qué alcanza cambiar esta clave (RB-4).
     *
     * Las tres que se congelan al crear son las que RB-4 y sus requisitos
     * nombran: la ventana del inicial no mueve un `expires_at` ya fijado
     * (`REQ-LAY-006`), y la frecuencia y el número de cuotas viajan en el
     * *snapshot* del apartado (`REQ-LAY-002`, `REQ-LAY-004` SP-8).
     */
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

    /**
     * Nombre con el que aparece en la pantalla de ajustes.
     */
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
            self::OverdueAlertDays => 'Días de mora para la alerta del panel',
        };
    }

    /**
     * Las diez claves con su valor por defecto, listas para sembrar.
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

    /**
     * Clave a partir de su cadena, o `null` si no la conocemos.
     *
     * **La puerta silenciosa de RB-2**, y la que usa todo lo que llega de
     * fuera: el formulario de ajustes y las filas que lee `Settings`. Una
     * clave que no reconocemos se ignora, que es lo que permite desplegar una
     * versión que ya escribe una clave nueva contra filas sembradas antes, y
     * leer una base donde todavía vive `storefront_enabled`.
     */
    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom($key);
    }

    /**
     * Clave a partir de su cadena, o excepción.
     *
     * La puerta ruidosa, para cuando es **nuestro** código el que nombra la
     * clave: ahí un valor desconocido es una errata, y leer un default en
     * lugar del ajuste del negocio no lo notaría nadie.
     *
     * @throws UnknownSettingException
     */
    public static function fromKey(string $key): self
    {
        return self::tryFrom($key) ?? throw UnknownSettingException::named($key);
    }
}
