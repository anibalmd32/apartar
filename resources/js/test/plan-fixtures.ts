import { type PlanFeature, type PlanState, type PlanStateOffers, type UsageMetric, type UsageState } from '@/types/plan';

/** Los nombres con los que el servidor manda las métricas (`UsageMetric::label()`). */
export const METRIC_LABELS: Readonly<Record<UsageMetric, string>> = {
    users: 'usuarios',
    catalogs: 'catálogos publicados',
    products: 'productos',
    active_layaways: 'apartados activos',
    storage_mb: 'MB de almacenamiento',
    payment_accounts: 'cuentas de cobro',
};

/** Los nombres con los que el servidor manda las funciones (`PlanFeature::label()`). */
export const FEATURE_LABELS: Readonly<Record<PlanFeature, string>> = {
    branding: 'Tu logo y tu color, sin el sello de Apartar',
    excel_export: 'Exportación a Excel',
    web_push: 'Notificaciones push',
    subscriber_digest: 'Resumen de novedades para quienes siguen tus catálogos',
    full_analytics: 'Analítica completa',
    custom_domain: 'Dominio propio',
};

/** Lo consumido de una métrica, con el porcentaje como lo redondea el servidor. */
export function usage(used: number, limit: number | null): UsageState {
    return {
        used,
        limit,
        isAtLimit: limit !== null && used >= limit,
        percentage: limit === null || limit <= 0 ? null : Math.min(100, Math.round((used / limit) * 100)),
    };
}

/** El `planState` que comparte `HandleInertiaRequests` (`T-067`): Free, holgado, sin funciones. */
export function planState(overrides: Partial<PlanState & PlanStateOffers> = {}): PlanState & PlanStateOffers {
    return {
        planCode: 'free',
        planName: 'Free',
        status: 'active',
        currentPeriodEnd: null,
        graceEndsAt: null,
        usage: {
            users: usage(1, 2),
            catalogs: usage(0, 1),
            products: usage(3, 30),
            active_layaways: usage(2, 15),
            storage_mb: usage(10, 500),
            payment_accounts: usage(1, 3),
        },
        features: [],
        upgrades: {},
        featureUpgrades: {
            branding: { planCode: 'pro', planName: 'Pro' },
            excel_export: { planCode: 'pro', planName: 'Pro' },
            web_push: { planCode: 'business', planName: 'Business' },
            subscriber_digest: { planCode: 'pro', planName: 'Pro' },
            full_analytics: { planCode: 'business', planName: 'Business' },
            custom_domain: null,
        },
        featureLabels: FEATURE_LABELS,
        metricLabels: METRIC_LABELS,
        ...overrides,
    };
}
