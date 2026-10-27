import { type PlanFeature, type PlanOption, type PlanState, type PlanStateOffers, type UsageMetric, type UsageState } from '@/types/plan';

export const METRIC_LABELS: Readonly<Record<UsageMetric, string>> = {
    users: 'usuarios',
    catalogs: 'catálogos publicados',
    products: 'productos',
    active_layaways: 'apartados activos',
    storage_mb: 'MB de almacenamiento',
    payment_accounts: 'cuentas de cobro',
};

export const METRIC_LABELS_ONE: Readonly<Record<UsageMetric, string>> = {
    users: 'usuario',
    catalogs: 'catálogo publicado',
    products: 'producto',
    active_layaways: 'apartado activo',
    storage_mb: 'MB de almacenamiento',
    payment_accounts: 'cuenta de cobro',
};

export const FEATURE_LABELS: Readonly<Record<PlanFeature, string>> = {
    branding: 'Tu logo y tu color, sin el sello de Apartar',
    excel_export: 'Exportación a Excel',
    web_push: 'Notificaciones push',
    subscriber_digest: 'Resumen de novedades para quienes siguen tus catálogos',
    full_analytics: 'Analítica completa',
    custom_domain: 'Dominio propio',
};

export function usage(used: number, limit: number | null): UsageState {
    return {
        used,
        limit,
        isAtLimit: limit !== null && used >= limit,
        percentage: limit === null || limit <= 0 ? null : Math.min(100, Math.round((used / limit) * 100)),
    };
}

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
        metricLabelsOne: METRIC_LABELS_ONE,
        ...overrides,
    };
}

export function planOption(overrides: Partial<PlanOption> = {}): PlanOption {
    return {
        code: 'pro',
        name: 'Pro',
        priceCents: 0,
        limits: { users: 5, catalogs: 10, products: 300, active_layaways: 200, storage_mb: 5120, payment_accounts: 5 },
        productsPerCatalog: 100,
        features: ['excel_export'],
        isCurrent: false,
        isScheduled: false,
        preview: {
            direction: 'upgrade',
            effectiveAt: '2026-10-09T12:00:00-04:00',
            unpublishAt: '2026-10-16T12:00:00-04:00',
            excesses: [],
            productsPerCatalog: null,
            lostFeatures: [],
            gainedFeatures: ['excel_export'],
        },
        ...overrides,
    };
}
