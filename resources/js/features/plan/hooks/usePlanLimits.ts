import {
    type PlanFeature,
    type PlanOffer,
    type PlanSharedProps,
    type PlanState,
    type PlanStateOffers,
    type UsageMetric,
    type UsageState,
} from '@/types/plan';
import { usePage } from '@inertiajs/react';

export const WARNING_PERCENTAGE = 80;

export interface PlanLimits {
    readonly state: (PlanState & PlanStateOffers) | null;
    usage(metric: UsageMetric): UsageState | null;
    isNearLimit(metric: UsageMetric): boolean;
    isAtLimit(metric: UsageMetric): boolean;
    upgradeFor(metric: UsageMetric): PlanOffer | null;
    hasFeature(feature: PlanFeature): boolean;
    featureUpgrade(feature: PlanFeature): PlanOffer | null;
    featureLabel(feature: PlanFeature): string;
    metricLabel(metric: UsageMetric, count?: number | null): string;
    readonly canOpenSubscription: boolean;
    readonly plansOffered: boolean;
}

export function usePlanLimits(): PlanLimits {
    const { planState, teamMember } = usePage<Partial<PlanSharedProps> & { teamMember?: { capabilities?: readonly string[] } | null }>().props;

    return planLimitsOf(planState ?? null, teamMember?.capabilities?.includes('subscription') ?? false);
}

export function planLimitsOf(state: (PlanState & PlanStateOffers) | null, canOpenSubscription = true): PlanLimits {
    const usage = (metric: UsageMetric): UsageState | null => state?.usage[metric] ?? null;

    return {
        state,
        usage,
        isNearLimit: (metric) => {
            const current = usage(metric);

            return current !== null && (current.isAtLimit || (current.percentage !== null && current.percentage >= WARNING_PERCENTAGE));
        },
        isAtLimit: (metric) => usage(metric)?.isAtLimit ?? false,
        upgradeFor: (metric) => state?.upgrades[metric] ?? null,
        hasFeature: (feature) => state === null || state.features.includes(feature),
        featureUpgrade: (feature) => state?.featureUpgrades[feature] ?? null,
        featureLabel: (feature) => state?.featureLabels[feature] ?? feature,
        metricLabel: (metric, count) => (count === 1 ? state?.metricLabelsOne?.[metric] : undefined) ?? state?.metricLabels[metric] ?? metric,
        canOpenSubscription,
        plansOffered: state?.plansOffered ?? true,
    };
}
