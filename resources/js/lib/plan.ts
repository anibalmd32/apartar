import type { PlanLimit } from '@/types/plan';

export const UNLIMITED_LABEL = 'ilimitado';

export function isUnlimited(limit: PlanLimit): boolean {
    return limit === null;
}

export function formatLimit(limit: PlanLimit): string {
    return isUnlimited(limit) ? UNLIMITED_LABEL : new Intl.NumberFormat('es-VE').format(limit as number);
}

export function formatUsage(used: number, limit: PlanLimit): string {
    return `${new Intl.NumberFormat('es-VE').format(used)} de ${formatLimit(limit)}`;
}

export function usagePercentage(used: number, limit: PlanLimit): number | null {
    if (isUnlimited(limit) || (limit as number) <= 0) {
        return null;
    }

    return Math.min(100, Math.round((used / (limit as number)) * 100));
}
