import type { CentsUsd, MoneyView } from '@/types/money';

/**
 * Ayudas de presentación sobre importes ya resueltos (`REQ-FX-001` RB-6).
 *
 * **Aquí no hay aritmética de dinero, y no es una omisión.** La conversión va
 * por `bcmul` en el servidor y el formateo por `MoneyFormatter`, así que lo
 * único que llega a este archivo son cadenas hechas y una cifra de céntimos
 * para ordenar. Si alguna vez aparece una multiplicación en este archivo, la
 * cuenta está en el sitio equivocado: el redondeo de JavaScript sobre
 * `number` es exactamente el error que todo el diseño monetario evita.
 */

/**
 * Marca un número como céntimos de dólar.
 *
 * Es la única frontera por la que se construye un `CentsUsd`, y está pensada
 * para el borde: lo que llega en las page props es un `number` cualquiera
 * hasta que alguien afirma qué representa.
 *
 * No redondea ni corrige: si el valor no es entero, la afirmación es falsa y
 * lo que hay que arreglar está del lado del servidor.
 */
export function centsUsd(value: number): CentsUsd {
    if (!Number.isInteger(value)) {
        throw new TypeError(`Los céntimos son enteros y llegó ${value}. Un importe fraccionario significa que alguien convirtió con coma flotante.`);
    }

    return value as CentsUsd;
}

/**
 * Indica si el importe tiene proyección en bolívares.
 *
 * Es la pregunta de la que cuelga toda la degradación de `REQ-FX-005` RB-4:
 * sin tasa, la interfaz **oculta** el monto en VES y deshabilita el toggle, en
 * lugar de enseñar `Bs. 0,00`. Está escrita una sola vez para que ninguna
 * pantalla la resuelva con un `money.ves || '—'`, que es la forma exacta de
 * convertir la ausencia en un guion y el guion en «aquí no hay precio».
 */
export function hasVes(money: MoneyView): boolean {
    return money.ves !== null;
}

/**
 * Las representaciones disponibles del importe, en el orden en que se piden.
 *
 * Devuelve una sola cuando no hay tasa, y ese es el punto: quien pinte el par
 * no tiene que acordarse de comprobar `ves` antes de escribirlo entre
 * paréntesis.
 */
export function availableLabels(money: MoneyView): string[] {
    return hasVes(money) ? [money.usd, money.ves as string] : [money.usd];
}
