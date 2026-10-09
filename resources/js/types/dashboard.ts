import { type RateView } from '@/types/exchange';
import { type MoneyView } from '@/types/money';
import { type PlanState, type UsageMetric } from '@/types/plan';

/**
 * Los avisos del panel (`REQ-BOF-044` RB-4): condiciones con las que el negocio
 * pierde ventas o dinero, cada una con su acción. `plan_limit_blocking_sales`
 * llega con `T-067`.
 */
export type DashboardWarning =
    | { readonly kind: 'no_active_payment_account' }
    | { readonly kind: 'published_catalog_empty'; readonly catalogSlug: string }
    | { readonly kind: 'product_published_in_no_catalog'; readonly count: number }
    | { readonly kind: 'stale_exchange_rate'; readonly ageHours: number }
    | { readonly kind: 'plan_limit_blocking_sales'; readonly metric: UsageMetric }
    | { readonly kind: 'invoice_overdue'; readonly dueDate: string | null }
    | { readonly kind: 'email_not_verified' };

/**
 * Las cifras (`REQ-BOF-044` RB-1). Una métrica que falló llega `null` y su
 * tarjeta enseña un guion (SP-4); las que aún no existen —mensajes, H6—,
 * también `null`, y no se pintan.
 */
export interface DashboardMetrics {
    readonly pendingReceipts: number | null;
    readonly overdueReceiptsCount: number | null;
    readonly collectedThisMonth: MoneyView | null;
    readonly dueThisWeek: { readonly count: number; readonly amount: MoneyView } | null;
    readonly customersOverdue: number | null;
    readonly longOverdueLayaways: number | null;
    readonly completedNotDelivered: number | null;
    readonly unreadMessages: number | null;
    readonly rate: RateView | null;
    readonly plan: PlanState | null;
    readonly warnings: readonly DashboardWarning[];
    readonly subscribers: { readonly total: number; readonly last7Days: number } | null;
    /** El checklist de arranque (SP-1, `REQ-ORG-001`). */
    readonly setup: {
        readonly emailVerified: boolean;
        readonly paymentAccount: boolean;
        readonly product: boolean;
        readonly publishedCatalog: boolean;
    } | null;
}

export interface DashboardProps {
    readonly metrics: DashboardMetrics;
    readonly links: {
        readonly receipts: string;
        readonly dueThisWeek: string;
        readonly customersOverdue: string;
        readonly longOverdue: string;
        readonly completedNotDelivered: string;
    };
}
