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
export type UsageMetric =
    | 'users'
    | 'catalogs'
    | 'products'
    | 'active_layaways'
    | 'storage_mb'
    | 'payment_accounts';

/** Funciones que un plan tiene o no tiene. */
export type PlanFeature =
    | 'branding'
    | 'excel_export'
    | 'web_push'
    | 'subscriber_digest'
    | 'full_analytics'
    | 'custom_domain';

/** Estado de la suscripción (`REQ-SUB-007`). */
export type SubscriptionStatus = 'active' | 'past_due' | 'grace' | 'suspended' | 'cancelled';

export interface UsageState {
    readonly used: number;
    /** null = ilimitado. */
    readonly limit: PlanLimit;
    readonly isAtLimit: boolean;
    readonly percentage: number;
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
