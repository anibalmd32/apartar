import { formatUsage } from '@/lib/plan';
import { type PlanLimit, type UsageMetric } from '@/types/plan';

const MB_PER_GB = 1024;

function storageAmount(megabytes: number): string {
    if (megabytes < MB_PER_GB) {
        return `${new Intl.NumberFormat('es-VE').format(megabytes)} MB`;
    }

    return `${new Intl.NumberFormat('es-VE', { maximumFractionDigits: 1 }).format(megabytes / MB_PER_GB)} GB`;
}

export function usageLabel(metric: UsageMetric, label: string): string {
    return metric === 'storage_mb' ? 'Almacenamiento' : label;
}

export function usageValue(metric: UsageMetric, used: number, limit: PlanLimit): string {
    if (metric !== 'storage_mb') {
        return formatUsage(used, limit);
    }

    return limit === null ? `${storageAmount(used)} de ilimitado` : `${storageAmount(used)} de ${storageAmount(limit)}`;
}
