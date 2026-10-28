import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { PendingUnpublishNotice, type PendingUnpublish } from '@/features/plan/components/PendingUnpublishNotice';
import { PlanOptionCard } from '@/features/plan/components/PlanOptionCard';
import { ScheduledChangeNotice } from '@/features/plan/components/ScheduledChangeNotice';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { PlanSummary, type NextInvoice, type SubscriptionPeriod } from '@/features/subscription/components/PlanSummary';
import { CloseOrganizationCard } from '@/features/tenancy/components/CloseOrganizationCard';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type PlanFeature, type PlanOption, type ScheduledPlanChange, type UsageMetric } from '@/types/plan';
import { cilCheckAlt, cilDescription } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface SubscriptionShowProps {
    readonly pricingUrl: string;
    readonly canChangePlan: boolean;
    readonly scheduledChange: ScheduledPlanChange | null;
    readonly plans: readonly PlanOption[];
    readonly timezone: string;
    readonly invoicesUrl: string | null;
    readonly closeUrl: string | null;
    readonly pendingUnpublish: PendingUnpublish | null;
    readonly period: SubscriptionPeriod | null;
    readonly nextInvoice: NextInvoice | null;
    readonly needsDocument?: boolean;
}

export default function SubscriptionShow({
    pricingUrl,
    canChangePlan,
    scheduledChange,
    plans,
    timezone,
    invoicesUrl,
    closeUrl,
    pendingUnpublish,
    period,
    nextInvoice,
    needsDocument = false,
}: SubscriptionShowProps) {
    const plan = usePlanLimits();
    const state = plan.state;

    return (
        <BackofficeLayout
            title="Suscripción"
            description="Tu plan, tu consumo y tus facturas con Apartar."
            actions={
                invoicesUrl !== null ? (
                    <Link className="btn" href={invoicesUrl}>
                        <Icon icon={cilDescription} />
                        Ver facturas
                    </Link>
                ) : null
            }
        >
            {state === null ? (
                <Alert tone="warning">No encontramos la suscripción de tu negocio. Escríbenos y lo revisamos.</Alert>
            ) : (
                <>
                    <PlanSummary
                        state={state}
                        pricingUrl={plan.plansOffered ? pricingUrl : null}
                        period={period}
                        nextInvoice={nextInvoice}
                        timeZone={timezone}
                    />

                    <section aria-label="Consumo" className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        {(Object.keys(state.usage) as UsageMetric[]).map((metric) => (
                            <div key={metric} className="bg-base-100 border-line shadow-card rounded-xl border px-3.5 py-3">
                                <Meter
                                    label={plan.metricLabel(metric)}
                                    value={formatUsage(state.usage[metric].used, state.usage[metric].limit)}
                                    percentage={state.usage[metric].percentage}
                                    warn={plan.isNearLimit(metric)}
                                />
                            </div>
                        ))}
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

                    {pendingUnpublish !== null ? <PendingUnpublishNotice pending={pendingUnpublish} timeZone={timezone} /> : null}

                    {(Object.keys(state.usage) as UsageMetric[]).map((metric) => (
                        <LimitBanner key={metric} metric={metric} />
                    ))}

                    <section className="flex flex-col gap-3" aria-labelledby="plan-options">
                        <h2 id="plan-options" className="text-h2">
                            Cambiar de plan
                        </h2>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,240px),1fr))] items-stretch gap-4">
                            {plans.map((option) => (
                                <PlanOptionCard
                                    key={option.code}
                                    option={option}
                                    canChange={canChangePlan}
                                    timeZone={timezone}
                                    needsDocument={needsDocument}
                                />
                            ))}
                        </div>
                    </section>

                    <SectionCard title="Funciones">
                        <ul className="grid grid-cols-1 gap-2.5 text-[13px] md:grid-cols-2">
                            {(Object.keys(state.featureLabels) as PlanFeature[]).map((feature) =>
                                plan.hasFeature(feature) ? (
                                    <li key={feature} className="flex items-center gap-2">
                                        <Icon icon={cilCheckAlt} size={14} className="text-success" />
                                        {plan.featureLabel(feature)}
                                    </li>
                                ) : (
                                    <li key={feature}>
                                        <UpgradePrompt feature={feature} />
                                    </li>
                                ),
                            )}
                        </ul>
                    </SectionCard>

                    {closeUrl !== null ? <CloseOrganizationCard closeUrl={closeUrl} /> : null}
                </>
            )}
        </BackofficeLayout>
    );
}
