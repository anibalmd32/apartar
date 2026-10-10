import { Alert } from '@/components/ui/alert';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { PlanOptionCard } from '@/features/plan/components/PlanOptionCard';
import { ScheduledChangeNotice } from '@/features/plan/components/ScheduledChangeNotice';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type PlanFeature, type PlanOption, type ScheduledPlanChange, type UsageMetric } from '@/types/plan';

interface SubscriptionShowProps {
    readonly pricingUrl: string;
    readonly canChangePlan: boolean;
    readonly scheduledChange: ScheduledPlanChange | null;
    readonly plans: readonly PlanOption[];
    /** La zona del negocio, para que las fechas se lean el mismo día que en el mensaje del servidor. */
    readonly timezone: string;
}

const STATUS_LABELS = {
    active: 'Al día',
    past_due: 'Pago pendiente',
    grace: 'Cambio de plan programado',
    suspended: 'Suspendida',
    cancelled: 'Cancelada',
} as const;

/**
 * Suscripción (`REQ-SUB-001`, `REQ-SUB-003` RB-6, RB-7, `REQ-SUB-004` RB-7,
 * `T-067`): el plan, lo consumido de cada métrica con su aviso a partir del
 * 80 %, y las funciones —las que incluye y, **a la vista**, las que no, con el
 * plan que las trae—. Todo sale de `usePlanLimits`; esta página no decide
 * nada. Las facturas llegan con `T-069`.
 *
 * `T-068` · y el cambio de plan, aquí y no en otra pantalla: es el destino de
 * cada «Ver planes». Cada plan del escaparate llega con su previsualización ya
 * calculada (`PlanChangePreview`), así que lo que excede se ve **antes** de
 * pulsar (`REQ-SUB-005` RB-5); y una bajada programada se puede deshacer desde
 * el aviso (RB-7).
 */
export default function SubscriptionShow({ pricingUrl, canChangePlan, scheduledChange, plans, timezone }: SubscriptionShowProps) {
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

                    {scheduledChange !== null ? (
                        <ScheduledChangeNotice
                            change={scheduledChange}
                            currentPlanCode={state.planCode}
                            currentPlanName={state.planName}
                            canChange={canChangePlan}
                            timeZone={timezone}
                        />
                    ) : null}

                    {(Object.keys(state.usage) as UsageMetric[]).map((metric) => (
                        <LimitBanner key={metric} metric={metric} />
                    ))}

                    <section className="flex flex-col gap-3" aria-labelledby="plan-options">
                        <h2 id="plan-options" className="text-base font-semibold">
                            Cambiar de plan
                        </h2>
                        <div className="grid gap-3 md:grid-cols-3">
                            {plans.map((option) => (
                                <PlanOptionCard key={option.code} option={option} canChange={canChangePlan} timeZone={timezone} />
                            ))}
                        </div>
                    </section>

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
