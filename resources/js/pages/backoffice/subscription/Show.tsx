import { Alert } from '@/components/ui/alert';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type PlanFeature, type UsageMetric } from '@/types/plan';

interface SubscriptionShowProps {
    readonly pricingUrl: string;
}

const STATUS_LABELS = {
    active: 'Al día',
    past_due: 'Pago pendiente',
    grace: 'En período de gracia',
    suspended: 'Suspendida',
    cancelled: 'Cancelada',
} as const;

/**
 * Suscripción (`REQ-SUB-001`, `REQ-SUB-003` RB-6, RB-7, `REQ-SUB-004` RB-7,
 * `T-067`): el plan, lo consumido de cada métrica con su aviso a partir del
 * 80 %, y las funciones —las que incluye y, **a la vista**, las que no, con el
 * plan que las trae—. Todo sale de `usePlanLimits`; esta página no decide
 * nada. Cambiar de plan llega con `T-068`; las facturas, con `T-069`.
 */
export default function SubscriptionShow({ pricingUrl }: SubscriptionShowProps) {
    const plan = usePlanLimits();
    const state = plan.state;

    return (
        <BackofficeLayout title="Suscripción" description="Tu plan, lo que llevas consumido y lo que incluye.">
            {state === null ? (
                <Alert tone="warning">No encontramos la suscripción de tu negocio. Escríbenos y lo revisamos.</Alert>
            ) : (
                <>
                    <section className="card bg-base-100 shadow-sm">
                        <div className="card-body gap-3">
                            <header className="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 className="card-title text-base">Plan {state.planName}</h2>
                                <span className="badge badge-outline">{STATUS_LABELS[state.status]}</span>
                            </header>

                            <ul className="flex flex-col gap-1 text-sm">
                                {(Object.keys(state.usage) as UsageMetric[]).map((metric) => (
                                    <li key={metric}>
                                        <span className="capitalize">{plan.metricLabel(metric)}</span>:{' '}
                                        {formatUsage(state.usage[metric].used, state.usage[metric].limit)}
                                    </li>
                                ))}
                            </ul>

                            <a className="link text-sm" href={pricingUrl}>
                                Compara los planes
                            </a>
                        </div>
                    </section>

                    {(Object.keys(state.usage) as UsageMetric[]).map((metric) => (
                        <LimitBanner key={metric} metric={metric} />
                    ))}

                    <section className="card bg-base-100 shadow-sm">
                        <div className="card-body gap-2">
                            <h2 className="card-title text-base">Funciones</h2>

                            <ul className="flex flex-col gap-2 text-sm">
                                {(Object.keys(state.featureLabels) as PlanFeature[]).map((feature) =>
                                    plan.hasFeature(feature) ? (
                                        <li key={feature}>✓ {plan.featureLabel(feature)}</li>
                                    ) : (
                                        <li key={feature}>
                                            <UpgradePrompt feature={feature} />
                                        </li>
                                    ),
                                )}
                            </ul>
                        </div>
                    </section>
                </>
            )}
        </BackofficeLayout>
    );
}
