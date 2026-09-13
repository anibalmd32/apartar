import type { PlanLimit } from '@/types/plan';

/**
 * Lectura de los límites de plan en la interfaz (`REQ-SUB-001` RB-2 y SP-2).
 *
 * Existe por una razón muy concreta: **un `0` mostrado donde va «ilimitado»
 * convierte el mejor plan en el peor**. El `null` viaja correcto desde la
 * columna hasta las props, y el sitio donde se estropea es siempre el último,
 * al pintarlo, porque `limit ?? 0` o un `Number(limit)` parecen inofensivos.
 * Con estas dos funciones, ese paso está escrito una vez y probado.
 */

/** Palabra con la que se muestra la ausencia de techo. */
export const UNLIMITED_LABEL = 'ilimitado';

/**
 * Indica si la métrica no tiene techo.
 */
export function isUnlimited(limit: PlanLimit): boolean {
    return limit === null;
}

/**
 * Texto del techo: la cifra, o la palabra «ilimitado».
 */
export function formatLimit(limit: PlanLimit): string {
    return isUnlimited(limit) ? UNLIMITED_LABEL : new Intl.NumberFormat('es-VE').format(limit as number);
}

/**
 * Texto de «usado de disponible», tal como se lee en el backoffice.
 */
export function formatUsage(used: number, limit: PlanLimit): string {
    return `${new Intl.NumberFormat('es-VE').format(used)} de ${formatLimit(limit)}`;
}

/**
 * Porcentaje consumido, o `null` si no hay techo contra el que medir.
 *
 * Sin techo no hay porcentaje: devolver `100` —o `0`— daría una barra de
 * progreso que miente sobre un plan que no tiene límite.
 */
export function usagePercentage(used: number, limit: PlanLimit): number | null {
    if (isUnlimited(limit) || (limit as number) <= 0) {
        return null;
    }

    return Math.min(100, Math.round((used / (limit as number)) * 100));
}
