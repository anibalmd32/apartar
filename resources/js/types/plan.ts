/**
 * Contratos del plan y el consumo (`REQ-SUB-001`, `REQ-SUB-003`).
 *
 * Espejo tipado de lo que el servidor envía en las page props. Las páginas de
 * precios y de suscripción llegan con sus hitos; estos tipos existen desde ya
 * porque son lo que impide que el `null` de «ilimitado» se convierta en un `0`
 * en el camino (`REQ-SUB-001` SP-2).
 */

/**
 * Techo de una métrica. **`null` es ilimitado**, nunca «sin definir» ni cero.
 *
 * El tipo no puede impedir que alguien lo pinte mal, pero sí obliga a
 * contemplar el caso: sin la unión, un `limit` sería `number` y el `0` se
 * colaría sin que TypeScript dijera nada.
 */
export type PlanLimit = number | null;

/** Lo que una organización consume y que un día se le acaba. */
export type UsageMetric = 'users' | 'catalogs' | 'products' | 'active_layaways' | 'storage_mb' | 'payment_accounts';

/** Funciones que un plan tiene o no tiene. */
export type PlanFeature = 'branding' | 'excel_export' | 'web_push' | 'subscriber_digest' | 'full_analytics' | 'custom_domain';

/** Estado de la suscripción (`REQ-SUB-007`). */
export type SubscriptionStatus = 'active' | 'past_due' | 'grace' | 'suspended' | 'cancelled';

export interface UsageState {
    readonly used: number;
    /** null = ilimitado. */
    readonly limit: PlanLimit;
    readonly isAtLimit: boolean;
    /**
     * `null` cuando no hay techo contra el que medir.
     *
     * Es la misma unión que devuelve `usagePercentage()` en `lib/plan.ts`, y
     * por el mismo motivo: un `0` pintaría una barra vacía que miente sobre un
     * plan ilimitado y un `100` una llena que miente al revés. Lo fija el
     * servidor en `UsageState` (`T-013`); antes el tipo decía `number` y no
     * tenía a nadie detrás que lo produjera.
     */
    readonly percentage: number | null;
}

export interface PlanState {
    readonly planCode: string;
    readonly planName: string;
    readonly status: SubscriptionStatus;
    /** null en Free: la suscripción no tiene fecha de fin (`REQ-SUB-002` RB-2). */
    readonly currentPeriodEnd: string | null;
    readonly graceEndsAt: string | null;
    readonly usage: Readonly<Record<UsageMetric, UsageState>>;
    readonly features: readonly PlanFeature[];
}

/** Un plan del escaparate que se ofrece como salida, ya resuelto por el servidor. */
export interface PlanOffer {
    readonly planCode: string;
    readonly planName: string;
}

/**
 * Lo que `LimitBanner` y `UpgradePrompt` necesitan para decir algo útil **sin
 * decidir nada** (`REQ-SUB-004` RB-1, `T-067`): el plan que da más sitio a
 * cada métrica que pasa del 80 % (`REQ-SUB-003` RB-7), el más barato que
 * incluye cada función que falta, y los nombres de unas y otras, que son del
 * servidor (`PlanFeature::label()`, `UsageMetric::label()`).
 */
export interface PlanStateOffers {
    readonly upgrades: Readonly<Partial<Record<UsageMetric, PlanOffer | null>>>;
    readonly featureUpgrades: Readonly<Partial<Record<PlanFeature, PlanOffer | null>>>;
    readonly featureLabels: Readonly<Record<PlanFeature, string>>;
    readonly metricLabels: Readonly<Record<UsageMetric, string>>;
}

/** El techo por catálogo, que no es una `UsageMetric` (`max_products_per_catalog`). */
export type LimitKey = UsageMetric | 'products_per_catalog';

/**
 * El rechazo por cupo, tal como lo deja en la sesión el render de
 * `PlanLimitReachedException` (`bootstrap/app.php`) para un formulario.
 */
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

/** El rechazo por una función que el plan no incluye (`REQ-SUB-004` SP-3). */
export interface PlanFeatureRejection {
    readonly message: string;
    readonly feature: {
        readonly feature: PlanFeature;
        readonly minimumPlan: string | null;
        readonly minimumPlanName: string | null;
    };
    readonly upgradeUrl: string;
}

/** Un intento de crear con la suscripción suspendida (`REQ-SUB-004` RB-8). */
export interface PlanBlockedRejection {
    readonly message: string;
    readonly upgradeUrl: string;
}

/** Lo que `HandleInertiaRequests` comparte del plan con cada página del backoffice. */
export interface PlanSharedProps {
    readonly planState: (PlanState & PlanStateOffers) | null;
    readonly planLimit: PlanLimitRejection | null;
    readonly planFeature: PlanFeatureRejection | null;
    readonly planBlocked: PlanBlockedRejection | null;
}

/** La pantalla de suscripción, destino de cada «Ver planes» (`T-067`). */
export const SUBSCRIPTION_URL = '/backoffice/suscripcion';
