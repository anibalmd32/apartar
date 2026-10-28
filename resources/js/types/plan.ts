export type PlanLimit = number | null;

export type UsageMetric = 'users' | 'catalogs' | 'products' | 'active_layaways' | 'storage_mb' | 'payment_accounts';

export type PlanFeature = 'branding' | 'excel_export' | 'web_push' | 'subscriber_digest' | 'full_analytics' | 'custom_domain';

export type SubscriptionStatus = 'active' | 'past_due' | 'grace' | 'suspended' | 'cancelled';

export interface UsageState {
    readonly used: number;
    readonly limit: PlanLimit;
    readonly isAtLimit: boolean;
    readonly percentage: number | null;
}

export interface PlanState {
    readonly planCode: string;
    readonly planName: string;
    readonly status: SubscriptionStatus;
    readonly currentPeriodEnd: string | null;
    readonly graceEndsAt: string | null;
    readonly usage: Readonly<Record<UsageMetric, UsageState>>;
    readonly features: readonly PlanFeature[];
}

export interface PlanOffer {
    readonly planCode: string;
    readonly planName: string;
}

export interface PlanStateOffers {
    readonly upgrades: Readonly<Partial<Record<UsageMetric, PlanOffer | null>>>;
    readonly featureUpgrades: Readonly<Partial<Record<PlanFeature, PlanOffer | null>>>;
    readonly plansOffered?: boolean;
    readonly featureLabels: Readonly<Record<PlanFeature, string>>;
    readonly metricLabels: Readonly<Record<UsageMetric, string>>;
    readonly metricLabelsOne?: Readonly<Record<UsageMetric, string>>;
}

export type LimitKey = UsageMetric | 'products_per_catalog';

export interface PlanLimitRejection {
    readonly message: string;
    readonly limit: {
        readonly metric: LimitKey;
        readonly used: number;
        readonly limit: number;
        readonly minimumPlan: string | null;
        readonly minimumPlanName: string | null;
        readonly suggestion: string;
    };
    readonly upgradeUrl: string;
}

export interface PlanFeatureRejection {
    readonly message: string;
    readonly feature: {
        readonly feature: PlanFeature;
        readonly minimumPlan: string | null;
        readonly minimumPlanName: string | null;
    };
    readonly upgradeUrl: string;
}

export interface PlanBlockedRejection {
    readonly message: string;
    readonly upgradeUrl: string;
}

export interface PlanSharedProps {
    readonly planState: (PlanState & PlanStateOffers) | null;
    readonly planLimit: PlanLimitRejection | null;
    readonly planFeature: PlanFeatureRejection | null;
    readonly planBlocked: PlanBlockedRejection | null;
}

export const SUBSCRIPTION_URL = '/backoffice/suscripcion';

export const CHANGE_PLAN_URL = '/backoffice/suscripcion/cambiar';

export interface PlanExcess {
    readonly metric: UsageMetric;
    readonly used: number;
    readonly limit: number;
    readonly excess: number;
    readonly consequence: 'unpublish' | 'block_creation';
}

export interface PlanChangePreview {
    readonly direction: 'upgrade' | 'downgrade' | 'same';
    readonly effectiveAt: string | null;
    readonly unpublishAt: string | null;
    readonly excesses: readonly PlanExcess[];
    readonly productsPerCatalog: {
        readonly limit: number;
        readonly catalogs: readonly { readonly name: string; readonly slug: string; readonly products: number }[];
        readonly count: number;
    } | null;
    readonly lostFeatures: readonly PlanFeature[];
    readonly gainedFeatures: readonly PlanFeature[];
    readonly retentionLoss?: { readonly fromDays: number; readonly toDays: number } | null;
}

export interface PlanOption {
    readonly code: string;
    readonly name: string;
    readonly priceCents: number;
    readonly limits: Readonly<Record<UsageMetric, PlanLimit>>;
    readonly productsPerCatalog: PlanLimit;
    readonly features: readonly PlanFeature[];
    readonly isCurrent: boolean;
    readonly isScheduled: boolean;
    readonly preview: PlanChangePreview;
    readonly invoicePreview?: {
        readonly periodStart: string;
        readonly periodEnd: string;
        readonly amount: string;
        readonly amountVes: string | null;
    } | null;
}

export interface ScheduledPlanChange {
    readonly planCode: string;
    readonly planName: string;
    readonly effectiveAt: string | null;
    readonly isCancellation: boolean;
}
