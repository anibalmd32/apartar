import { type MoneyView } from '@/types/money';

export interface OrganizationRow {
    readonly id: number;
    readonly slug: string;
    readonly name: string;
    readonly status: string;
    readonly planCode: string | null;
    readonly subscriptionStatus: string | null;
    readonly createdAt: string;
    readonly counts: {
        readonly users: number;
        readonly products: number;
        readonly publishedCatalogs: number;
        readonly customers: number | null;
        readonly activeLayaways: number;
        readonly subscribers: number | null;
    } | null;
    readonly volume: { readonly committed: MoneyView; readonly collectedLast30Days: MoneyView; readonly outstanding: MoneyView } | null;
    readonly lastActivityAt: string | null;
    readonly risks: { readonly atLimit: boolean; readonly inactive: boolean; readonly overdueInvoices: boolean };
    readonly hasActiveSupportAccess: boolean;
}

export interface PlatformMetrics {
    readonly organizations: { readonly total: number; readonly active: number; readonly suspended: number; readonly newLast30Days: number } | null;
    readonly byPlan: Readonly<Record<string, number>> | null;
    readonly mrr: MoneyView | null;
    readonly invoices: { readonly pendingReview: number; readonly overdue: number; readonly pendingReviewOld?: number } | null;
    readonly suspendedLongOverdue?: number | null;
    readonly monthly?: readonly { readonly month: string; readonly cents: number }[] | null;
    readonly periodDays?: number;
    readonly network: { readonly accounts: number; readonly subscriptions: number; readonly multiBusinessAccounts: number } | null;
    readonly gmv: { readonly committedLast30Days: MoneyView; readonly collectedLast30Days: MoneyView } | null;
    readonly health: { readonly failedJobs: number; readonly rateAgeHours: number | null; readonly queueBacklog: number } | null;
}

export interface SystemHealth {
    readonly queue: {
        readonly pending: number | null;
        readonly byQueue: Readonly<Record<string, number | null>>;
        readonly failed: number;
        readonly oldestPendingMinutes: number | null;
    } | null;
    readonly exchangeRate: { readonly source: string; readonly ageHours: number | null; readonly isStale: boolean } | null;
    readonly scheduledJobs:
        | readonly { readonly command: string; readonly lastRunAt: string | null; readonly isOverdue: boolean; readonly frequency?: string }[]
        | null;
    readonly storage: { readonly usedMb: number; readonly receiptsCount: number } | null;
    readonly realtime: { readonly connected: boolean };
    readonly reconciliation: {
        readonly discrepancies: number;
        readonly unresolved: number;
        readonly stock: number;
        readonly lastRunAt: string | null;
        readonly command: string;
    } | null;
}

export interface InvoiceRow {
    readonly id: number;
    readonly plan: string;
    readonly kind: string;
    readonly status: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly dueDate: string;
    readonly periodEnd: string;
    readonly voidReason: string | null;
}

export interface PendingSubscriptionReceipt {
    readonly id: number;
    readonly organization: { readonly id: number; readonly name: string };
    readonly invoiceId: number;
    readonly plan: string;
    readonly outstanding: MoneyView;
    readonly declared: string;
    readonly method: string;
    readonly bank: string | null;
    readonly reference: string | null;
    readonly paidAt: string;
    readonly submittedAt: string | null;
    readonly isLate: boolean;
    readonly fileUrl: string;
}

export interface PlatformSettingRow {
    readonly key: string;
    readonly label: string;
    readonly unit: string;
    readonly value: number;
    readonly default: number;
    readonly min: number;
    readonly max: number;
}

export interface PlatformOperatorRow {
    readonly id: number;
    readonly name: string;
    readonly email: string;
    readonly active: boolean;
    readonly mustChangePassword: boolean;
    readonly isMe: boolean;
}

export interface PlatformAuditEntry {
    readonly id: number;
    readonly action: string;
    readonly label: string;
    readonly description: string;
    readonly operator: string | null;
    readonly organization: { readonly name: string; readonly slug: string } | null;
    readonly createdAt: string;
}

export interface SupportGrantListRow extends SupportGrantRow {
    readonly organization: { readonly name: string; readonly slug: string | null };
}

export interface SupportGrantRow {
    readonly id: number;
    readonly operator: string;
    readonly reason: string;
    readonly grantedAt: string;
    readonly expiresAt: string;
    readonly revokedAt: string | null;
    readonly active: boolean;
}
