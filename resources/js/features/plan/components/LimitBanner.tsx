import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { PlansLink } from '@/features/plan/components/PlansLink';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { formatUsage } from '@/lib/plan';
import { type UsageMetric } from '@/types/plan';
import { cilDiamond } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

const PRODUCTS_URL = '/backoffice/productos';

export function LimitBanner({ metric }: { metric: UsageMetric }) {
    const plan = usePlanLimits();
    const onProducts = usePage().url.startsWith(PRODUCTS_URL);
    const usage = plan.usage(metric);

    if (plan.state === null || usage === null || !plan.isNearLimit(metric)) {
        return null;
    }

    const label = plan.metricLabel(metric);
    const upgrade = plan.upgradeFor(metric);
    const message = usage.isAtLimit
        ? `Llegaste al techo de tu plan ${plan.state.planName}: ${formatUsage(usage.used, usage.limit)} ${label}. No cabe uno más.`
        : `Llevas ${formatUsage(usage.used, usage.limit)} ${label} de tu plan ${plan.state.planName}.`;

    if (usage.isAtLimit) {
        return (
            <section role="alert" className="border-primary/40 bg-primary-soft/50 flex flex-col gap-3 rounded-2xl border p-6">
                <p className="text-primary-ink flex items-center gap-1.5 text-xs font-bold">
                    <Icon icon={cilDiamond} size={12} />
                    Plan {plan.state.planName}
                </p>
                <h2 className="text-h2 sm:text-[22px]">Sin sitio para más {label}</h2>
                <p className="text-ink-2 max-w-2xl text-sm">
                    {message} {upgrade !== null ? `${upgrade.planName} te da más sitio.` : null}
                </p>
                <div className="flex flex-wrap gap-2">
                    <PlansLink className="btn btn-primary" />
                    {metric === 'products' && !onProducts ? (
                        <Link href={PRODUCTS_URL} className="btn">
                            Archivar productos
                        </Link>
                    ) : null}
                </div>
            </section>
        );
    }

    return (
        <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
            <span>
                {message} {upgrade !== null ? `${upgrade.planName} te da más sitio.` : null}
            </span>

            <PlansLink />
        </Alert>
    );
}
