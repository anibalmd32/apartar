import { Alert } from '@/components/ui/alert';
import { PlansLink } from '@/features/plan/components/PlansLink';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { formatUsage } from '@/lib/plan';
import { type UsageMetric } from '@/types/plan';

/**
 * El aviso de una métrica que se acerca a su techo (`REQ-SUB-003` RB-7,
 * `REQ-SUB-004` RB-5, `T-067`).
 *
 * **No bloquea nada**: a partir del 80 % dice cuánto queda y qué plan da más
 * sitio, para que el negocio se entere **antes** de chocar. Al llegar al techo
 * dice que ya no cabe más. Por debajo del 80 % no existe: un aviso permanente
 * es ruido, y el ruido enseña a no leer los avisos.
 *
 * No decide nada (RB-1): lo consumido, el techo, el porcentaje y el plan que
 * se ofrece vienen del servidor (`usePlanLimits`).
 */
export function LimitBanner({ metric }: { metric: UsageMetric }) {
    const plan = usePlanLimits();
    const usage = plan.usage(metric);

    if (plan.state === null || usage === null || !plan.isNearLimit(metric)) {
        return null;
    }

    const label = plan.metricLabel(metric);
    const upgrade = plan.upgradeFor(metric);

    return (
        <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
            <span>
                {usage.isAtLimit
                    ? `Llegaste al techo de tu plan ${plan.state.planName}: ${formatUsage(usage.used, usage.limit)} ${label}. No cabe uno más.`
                    : `Llevas ${formatUsage(usage.used, usage.limit)} ${label} de tu plan ${plan.state.planName}.`}{' '}
                {upgrade !== null ? `${upgrade.planName} te da más sitio.` : null}
            </span>

            <PlansLink />
        </Alert>
    );
}
