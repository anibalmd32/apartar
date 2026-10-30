import { IconChip } from '@/components/ui/icon-chip';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { PlansLink } from '@/features/plan/components/PlansLink';
import { formatUsage, usagePercentage } from '@/lib/plan';
import { type DashboardMetrics } from '@/types/dashboard';
import { type UsageMetric } from '@/types/plan';
import { cilSwapHorizontal } from '@coreui/icons';

export function PlanAndRate({ metrics }: { metrics: DashboardMetrics }) {
    const plan = metrics.plan;

    return (
        <SectionCard
            title={plan !== null ? `Plan ${plan.planName}` : 'Tasa de cambio'}
            aria-label="Plan y tasa"
            action={plan !== null ? <PlansLink className="hover:underline" /> : null}
        >
            {plan !== null ? (
                <ul className="flex flex-col gap-3.5">
                    {(Object.keys(plan.usage) as UsageMetric[]).map((metric) => {
                        const usage = plan.usage[metric];
                        const percent = usage === undefined ? null : usagePercentage(usage.used, usage.limit);

                        return usage === undefined ? null : (
                            <Meter
                                key={metric}
                                as="li"
                                label={plan.metricLabels[metric] ?? metric}
                                value={formatUsage(usage.used, usage.limit)}
                                percentage={percent}
                                warn={percent !== null && percent >= 80}
                            />
                        );
                    })}
                </ul>
            ) : null}

            <div className="bg-surface-2 flex items-center gap-3 rounded-xl p-3">
                <IconChip icon={cilSwapHorizontal} tone={metrics.rate?.isStale ? 'warning' : 'success'} size={34} />
                {metrics.rate !== null && metrics.rate.rate !== null ? (
                    <div className="flex min-w-0 flex-col">
                        <span className="text-sm font-bold">
                            Bs. {metrics.rate.rate.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} por USD
                        </span>
                        <span className="text-ink-3 text-xs">
                            Fuente {metrics.rate.sourceLabel}
                            {metrics.rate.publishedAt !== null ? ` · actualizada hace ${ageOf(metrics.rate.publishedAt)}` : ''}
                            {metrics.rate.isStale ? ' (desactualizada)' : ''}
                        </span>
                    </div>
                ) : (
                    <span className="text-ink-2 text-[13px]">No hay tasa disponible: las cifras se muestran solo en dólares.</span>
                )}
            </div>
        </SectionCard>
    );
}

function ageOf(publishedAt: string): string {
    const hours = Math.max(0, Math.floor((Date.now() - new Date(publishedAt).getTime()) / 3_600_000));

    return hours < 48 ? `${hours} h` : `${Math.floor(hours / 24)} días`;
}
