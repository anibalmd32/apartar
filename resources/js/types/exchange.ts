/**
 * Contratos de la tasa de cambio (`REQ-FX-002`, `REQ-FX-003`, `REQ-FX-005`).
 *
 * Espejo tipado de `app/Domain/Exchange/`. La pantalla que los consume llega
 * con `T-022`; el tipo existe desde ya porque es lo que impide que la ausencia
 * de tasa se convierta en un `0` por el camino, que es la forma en que una
 * degradación se disfraza de precio.
 *
 * **El frontend nunca llama a la API externa** (`REQ-FX-002` RB-5): recibe
 * esto en las page props y lo refresca contra `GET /api/tasa?source=` cada 5
 * minutos.
 */

/**
 * Las tres fuentes de `RateSource`.
 *
 * La unión **nombra** los casos y nada más; qué significa cada uno para la
 * pantalla lo manda el servidor, por lo mismo que en `PaymentMethod`: un
 * diccionario de etiquetas escrito aquí sería el segundo censo de los mismos
 * tres casos, y el que se queda viejo es siempre el de la pantalla.
 */
export type RateSource = 'bcv' | 'parallel' | 'manual';

/**
 * Tasa vigente, presente en las page props de toda página con precios.
 *
 * `rate` es `number | null` y no `string`, al revés que en PHP, y es
 * deliberado: al frontend no le toca convertir —los importes llegan ya
 * proyectados y formateados en `MoneyView` (`REQ-FX-001` RB-6)—, así que aquí
 * la tasa solo se pinta en el sello. La aritmética exacta se queda del lado
 * donde hay `bcmul`.
 *
 * **`null` significa que no hay ninguna tasa**, y no cero: con `rate === null`
 * la interfaz oculta por completo el monto en VES y deshabilita el toggle
 * (`REQ-FX-005` RB-4). Pintar `Bs. 0,00` o un guion sería enseñar un precio
 * inventado donde no hay ninguno.
 */
export interface RateView {
    readonly rate: number | null;
    readonly source: RateSource;
    /** ISO 8601 · cuándo la **publicó** la fuente, no cuándo la leímos. */
    readonly publishedAt: string | null;
    /** Más de 24 h medidas sobre `publishedAt` (`REQ-FX-002` RB-3b). */
    readonly isStale: boolean;
}
