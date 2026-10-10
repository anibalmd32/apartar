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

/** A partir de cuánto se avisa sin bloquear (`REQ-SUB-003` RB-7). */
export const WARNING_PERCENTAGE = 80;

export interface PlanLimits {
    /** `null` fuera del backoffice o sin suscripción: entonces no se avisa de nada. */
    readonly state: (PlanState & PlanStateOffers) | null;
    usage(metric: UsageMetric): UsageState | null;
    /** RB-7 · a partir del 80 %, y también al llegar al techo. */
    isNearLimit(metric: UsageMetric): boolean;
    isAtLimit(metric: UsageMetric): boolean;
    /** El plan que da más sitio, si el servidor lo resolvió (solo a partir del 80 %). */
    upgradeFor(metric: UsageMetric): PlanOffer | null;
    hasFeature(feature: PlanFeature): boolean;
    /** El plan más barato que incluye la función, si falta. */
    featureUpgrade(feature: PlanFeature): PlanOffer | null;
    featureLabel(feature: PlanFeature): string;
    metricLabel(metric: UsageMetric): string;
    /**
     * Si quien mira puede abrir Suscripción (`SubscriptionPolicy::view`, la
     * capacidad `subscription`): a quien no, «Ver planes» le daría un `403`.
     */
    readonly canOpenSubscription: boolean;
}

/**
 * El plan y su consumo, para pintar (`REQ-SUB-004` RB-1, `T-067`).
 *
 * **El frontend nunca decide: solo refleja.** Todo lo que este hook contesta
 * sale de `planState`, que comparte `HandleInertiaRequests` desde
 * `LimitEnforcer::state()` —la misma cifra materializada con la que el
 * servidor decide—; y el plan que se ofrece como salida también viene
 * resuelto. Lo único que se calcula aquí es comparar un porcentaje con el
 * 80 %, que es una pregunta de pantalla. Si un botón se habilita de más, el
 * servidor sigue respondiendo `422` (SP-7).
 */
export function usePlanLimits(): PlanLimits {
    const { planState, teamMember } = usePage<Partial<PlanSharedProps> & { teamMember?: { capabilities?: readonly string[] } | null }>().props;

    return planLimitsOf(planState ?? null, teamMember?.capabilities?.includes('subscription') ?? false);
}

/** La misma lectura, sin React, para quien ya tiene el estado en la mano. */
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
        // Sin estado, la función se da por disponible: es el servidor el que
        // la niega, y un botón deshabilitado por no saber sería mentir.
        hasFeature: (feature) => state === null || state.features.includes(feature),
        featureUpgrade: (feature) => state?.featureUpgrades[feature] ?? null,
        featureLabel: (feature) => state?.featureLabels[feature] ?? feature,
        metricLabel: (metric) => state?.metricLabels[metric] ?? metric,
        canOpenSubscription,
    };
}
