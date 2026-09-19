<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

/**
 * De dónde sale la tasa con la que se proyecta un precio (`REQ-FX-003` RB-1).
 *
 * Son tres, y la línea que las separa **no** es «pública o privada», sino de
 * quién es el dato:
 *
 *  - `bcv` y `parallel` son cotizaciones públicas, iguales para todo el
 *    mundo. Se ingestan **una sola vez para toda la plataforma** (`D-6`,
 *    `REQ-FX-002` RB-0) y se persisten en `exchange_rates`, que por eso no
 *    lleva `organization_id`.
 *  - `manual` es la tasa que fija un negocio concreto. Vive en
 *    `organization_settings.exchange_rate_manual` y **nunca** llega a
 *    `exchange_rates` (`REQ-FX-003` RB-4): la tabla de plataforma guarda lo
 *    que es de todos, y una tasa manual es de uno solo.
 *
 * Lo que sí es por organización en los tres casos es **cuál se usa**
 * (`organization_settings.exchange_rate_source`). Dos negocios pueden mostrar
 * el mismo producto proyectado con tasas distintas, y cada catálogo lleva su
 * propio sello (`REQ-FX-005` RB-2).
 *
 * **Este enum es el censo de las tres fuentes, y el único.**
 * `SettingKey::ExchangeRateSource` valida contra él en lugar de repetir las
 * tres cadenas, que es como estaba hasta `T-019`: con dos listas, el día que
 * aparezca una cuarta fuente una de ellas se queda corta, y el síntoma no es
 * un test en rojo sino un `RateSource::from()` que lanza en producción sobre
 * un ajuste que la validación dio por bueno.
 *
 * Espejo de `RateSource` en `resources/js/types/exchange.ts`.
 */
enum RateSource: string
{
    /** Tasa oficial del Banco Central de Venezuela. */
    case Bcv = 'bcv';

    /** Tasa del mercado paralelo. */
    case Parallel = 'parallel';

    /** Tasa que fija el propio negocio en sus ajustes. */
    case Manual = 'manual';

    /**
     * Cómo se llama esta fuente para una persona.
     *
     * **Es el único nombre de cada fuente, y por eso está aquí.** Hasta
     * `T-022`, `ExchangeRateForm` guardaba las tres etiquetas en una constante
     * propia: con un solo consumidor no hacía daño, pero el sello del catálogo
     * escribe «Tasa BCV del 18/09 09:00» sobre estas mismas tres, y con dos
     * diccionarios la pantalla de ajustes y el catálogo pueden acabar llamando
     * cosas distintas a la misma tasa — delante de alguien que ya dudaba de la
     * cifra. Es el mismo problema que `PaymentMethod::label()` resolvió en
     * `T-015`, y se resuelve igual.
     *
     * Lo que un desplegable añada para **explicar** la opción —«tasa oficial»,
     * «la que yo fije»— es presentación de esa opción y vive en su pantalla:
     * no es un segundo nombre, es una frase de ayuda que solo tiene sentido
     * mientras se elige.
     *
     * El `match` es exhaustivo y sin `default`: una fuente nueva tiene que
     * decir cómo se llama en vez de heredar el nombre de la vecina.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bcv => 'BCV',
            self::Parallel => 'Paralelo',
            self::Manual => 'Manual',
        };
    }

    /**
     * Las fuentes **de plataforma**: las que se ingestan y se persisten.
     *
     * Es la misma lista que consulta el job, la que admite el endpoint público
     * y la que la pantalla de ajustes enseña con su antigüedad. Vive aquí
     * porque es una propiedad de las fuentes y no de ninguno de los tres: con
     * un censo por consumidor, el día que aparezca una cuarta pública habría
     * que acordarse de tres sitios, y el que se olvide no fallará —servirá de
     * menos, en silencio—.
     *
     * @return list<self>
     */
    public static function platform(): array
    {
        $platform = [];

        foreach (self::cases() as $source) {
            if ($source !== self::Manual) {
                $platform[] = $source;
            }
        }

        return $platform;
    }

    /**
     * Indica si esta fuente se ingesta y se guarda en `exchange_rates`.
     *
     * La manual no: es un dato de la organización que la escribió y su
     * histórico vive en la auditoría (`REQ-FX-003` RB-4).
     */
    public function isPlatformWide(): bool
    {
        return $this !== self::Manual;
    }
}
