import { type RateView } from '@/types/exchange';
import { type MoneyView } from '@/types/money';
import { type PlanState, type PlanStateOffers, type UsageMetric } from '@/types/plan';

export type DashboardWarning =
    | { readonly kind: 'no_active_payment_account' }
    | { readonly kind: 'published_catalog_empty'; readonly catalogSlug: string }
    | { readonly kind: 'product_published_in_no_catalog'; readonly count: number }
    | { readonly kind: 'stale_exchange_rate'; readonly ageHours: number }
    | { readonly kind: 'plan_limit_blocking_sales'; readonly metric: UsageMetric }
    | { readonly kind: 'invoice_overdue'; readonly dueDate: string | null }
    | { readonly kind: 'email_not_verified' };

export interface DashboardMetrics {
    readonly pendingReceipts: number | null;
    readonly overdueReceiptsCount: number | null;
    readonly collectedThisMonth: MoneyView | null;
    readonly customersOverdue: number | null;
    readonly longOverdueLayaways: number | null;
    readonly completedNotDelivered: number | null;
    readonly unreadMessages: number | null;
    readonly rate: RateView | null;
    readonly plan: (PlanState & PlanStateOffers) | null;
    readonly warnings: readonly DashboardWarning[];
    readonly subscribers: { readonly total: number; readonly last7Days: number } | null;
    readonly setup: {
        readonly emailVerified: boolean;
        readonly paymentAccount: boolean;
        readonly product: boolean;
        readonly publishedCatalog: boolean;
    } | null;
}

export interface DashboardPendingReceipt {
    readonly id: number;
    readonly reviewUrl: string;
    readonly customerName: string | null;
    readonly layawayCode: string | null;
    readonly methodLabel: string;
    readonly declared: string;
    readonly submittedAt: string | null;
    readonly overdue: boolean;
    readonly file: { readonly url: string; readonly kind: 'image' | 'pdf' };
}

export interface DashboardSuspension {
    readonly suspendedAt: string | null;
    readonly graceEndsAt: string | null;
    readonly invoice: {
        readonly number: string;
        readonly planName: string;
        readonly kind: string;
        readonly periodStart: string;
        readonly periodEnd: string;
        readonly dueDate: string;
        readonly daysOverdue: number;
        readonly amount: string;
        readonly amountVes: string | null;
    } | null;
}

export type DashboardMode = 'stacked' | 'split';

export type DashboardSection = 'stats' | 'plan';

export type DashboardCard =
    | 'pendingReceipts'
    | 'collectedThisMonth'
    | 'customersOverdue'
    | 'longOverdue'
    | 'completedNotDelivered'
    | 'unreadMessages';

export interface DashboardLayoutView {
    readonly mode: DashboardMode;
    readonly sections: readonly DashboardSection[];
    readonly cards: readonly DashboardCard[];
}

export interface DashboardProps {
    readonly metrics: DashboardMetrics;
    readonly layout?: DashboardLayoutView;
    readonly suspension?: DashboardSuspension | null;
    readonly longOverdueDays?: number;
    readonly pendingReceipts?: readonly DashboardPendingReceipt[] | null;
    readonly links: {
        readonly receipts: string;
        readonly customersOverdue: string;
        readonly longOverdue: string;
        readonly completedNotDelivered: string;
        readonly invoices?: string | null;
    };
}
