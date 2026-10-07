/**
 * Contratos del dinero en la interfaz (`REQ-FX-001`).
 *
 * Espejo tipado de `app/Domain/Money/`. La regla que los gobierna es la misma
 * que en PHP y se nota sobre todo aquí: **el frontend no hace aritmética de
 * dinero ni la convierte**. Recibe los importes ya proyectados y ya
 * formateados (RB-6) y solo decide cuál de las dos representaciones enseña,
 * según el toggle de `REQ-FX-004`.
 */

/**
 * Cuál de las dos monedas se muestra primero (`REQ-FX-004`).
 *
 * No cambia el importe, cambia el orden en que se lee: `$20.00 (Bs. 850,00)`
 * o `Bs. 850,00 ($20.00)`. Las dos representaciones viajan siempre en
 * `MoneyView`, así que elegir es puramente de presentación y **no genera
 * ninguna petición** (RB-6).
 *
 * Espejo de `CurrencyDisplay` en `app/Domain/Money/`.
 */
export type CurrencyDisplay = 'usd_primary' | 'ves_primary';

declare const centsUsdBrand: unique symbol;

/**
 * Céntimos de dólar, como tipo de marca sobre `number`.
 *
 * La marca no cambia nada en tiempo de ejecución —sigue siendo un número— y
 * sirve para una sola cosa: que **2000 céntimos no se pueda pasar donde van 20
 * dólares** sin que TypeScript lo note. Es el error más fácil de cometer con
 * dinero y el más difícil de ver leyendo, porque las dos cifras son `number` y
 * las dos parecen razonables.
 *
 * Solo se construye con `centsUsd()` en `lib/money.ts`, que es la frontera por
 * la que entran las page props.
 */
export type CentsUsd = number & { readonly [centsUsdBrand]: true };

/**
 * Un importe tal como se pinta.
 *
 * Espejo exacto de lo que compone el servidor. Lleva los céntimos **y** las
 * dos cadenas ya formateadas, que es lo que permite que el toggle de moneda
 * sea puramente de cliente (`REQ-FX-004` RB-6): cambiar de USD a VES no genera
 * una petición porque las dos representaciones ya están aquí.
 */
export interface MoneyView {
    /** La cifra exacta, para ordenar y comparar; nunca para formatear. */
    readonly cents: CentsUsd;
    /** Ya formateado: `$20.00`. */
    readonly usd: string;
    /**
     * Ya formateado: `Bs. 850,00`, o **`null` si no hay tasa**.
     *
     * `null` no es «cero bolívares» ni «todavía no calculado»: es que no hay
     * ninguna tasa con la que proyectar, y entonces `REQ-FX-005` RB-4 exige
     * **ocultar** el monto en VES por completo y deshabilitar el toggle. Ni
     * `Bs. 0,00` ni un guion: las dos cosas ponen en pantalla un precio que
     * nadie ha calculado.
     */
    readonly ves: string | null;
}

/**
 * Las dos monedas en las que se declara un pago (`REQ-PAY-001` RB-5).
 *
 * Espejo de `Currency` en `app/Domain/Money/`. Nombra la moneda, no un importe:
 * lo que se escribe junto a ella es una cadena decimal que el servidor
 * convierte al aprobar (`REQ-FX-006`), nunca aquí.
 */
export type Currency = 'USD' | 'VES';
