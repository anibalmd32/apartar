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
}
