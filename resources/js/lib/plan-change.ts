import { formatDate } from '@/lib/dates';
import { formatLimit } from '@/lib/plan';
import { type PlanExcess } from '@/types/plan';

export function describeExcess(excess: PlanExcess, metricLabel: string, planName: string, unpublishAt: string | null, timeZone?: string): string {
    const used = new Intl.NumberFormat('es-VE').format(excess.used);
    const limit = formatLimit(excess.limit);

    if (excess.consequence === 'unpublish') {
        const when = unpublishAt === null ? 'unos días después del cambio' : `a partir del ${formatDate(unpublishAt, 'long', timeZone)}`;

        return `Tienes ${used} ${metricLabel}; en ${planName} quedarán ${limit} y ${excess.excess} se despublicarán ${when}, conservando los más antiguos.`;
    }

    return `Tienes ${used} ${metricLabel} y ${planName} permite ${limit}: no se borra nada, pero no podrás añadir más hasta bajar de ${limit}.`;
}
