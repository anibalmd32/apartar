import { formatLimit } from '@/lib/plan';
import { type PlanExcess } from '@/types/plan';

/**
 * Las frases del cambio de plan (`REQ-SUB-005` RB-5, `REQ-SUB-008` RB-9,
 * `T-068`): «tienes 8 catálogos publicados; en Free quedará 1 y 7 se
 * despublicarán».
 *
 * Lo que dicen lo decidió el servidor —la cifra, el techo y la consecuencia
 * de cada exceso vienen en `PlanChangePreview`—; aquí solo se escribe, en un
 * sitio y probado, para que la pantalla no prometa algo distinto de lo que
 * hará la degradación.
 */

/**
 * Fecha corta de un instante ISO **en la zona del negocio**: la misma que usa
 * el mensaje del servidor. Con la del navegador, un corte a las 02:00 UTC se
 * leería un día distinto en Caracas y en Madrid.
 */
export function formatPlanDate(iso: string, timeZone?: string): string {
    return new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone }).format(new Date(iso));
}

/**
 * Qué pasa con un exceso al bajar a `planName`: los catálogos que sobran se
 * despublican, conservando los más antiguos (`REQ-SUB-008` RB-2); todo lo
 * demás se conserva y solo bloquea crear más (RB-1, RB-3 a RB-7).
 *
 * De los catálogos se dice «después del» día del cambio y no «el»: la
 * degradación los despublica con unos días de gracia desde el cambio
 * (`downgrade_grace_days`, de `T-072`), y prometer el día exacto sería
 * prometer de más.
 */
export function describeExcess(excess: PlanExcess, metricLabel: string, planName: string, effectiveAt: string | null, timeZone?: string): string {
    const used = new Intl.NumberFormat('es-VE').format(excess.used);
    const limit = formatLimit(excess.limit);

    if (excess.consequence === 'unpublish') {
        const when = effectiveAt === null ? 'después del cambio' : `después del ${formatPlanDate(effectiveAt, timeZone)}`;

        return `Tienes ${used} ${metricLabel}; en ${planName} quedarán ${limit} y ${excess.excess} se despublicarán ${when}, conservando los más antiguos.`;
    }

    return `Tienes ${used} ${metricLabel} y ${planName} permite ${limit}: no se borra nada, pero no podrás añadir más hasta bajar de ${limit}.`;
}
