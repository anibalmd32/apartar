import { type TableMeta } from '@/types/data-table';
import { type MoneyView } from '@/types/money';
import { type PaymentRow } from '@/types/receipt';

export interface CustomerDirectoryRow {
    readonly id: number;
    readonly url: string;
    readonly name: string;
    readonly document: string;
    readonly phone: string;
    readonly phoneLabel: string;
    readonly hasLinkedAccount: boolean;
    readonly activeLayaways: number;
    readonly committed: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly overdueInstallments: number;
    readonly inArrears: boolean;
    readonly nextDueDate: string | null;
}

export interface CustomerDirectoryFilters {
    readonly q: string | null;
    readonly overdue: string | null;
    readonly due_soon: string | null;
    readonly without_active: string | null;
    readonly from: string | null;
    readonly to: string | null;
    readonly catalog: string | null;
}

export interface CustomerIndexProps {
    readonly rows: readonly CustomerDirectoryRow[];
    readonly meta: TableMeta;
    readonly options: { readonly catalogs: readonly { readonly value: string; readonly label: string }[] };
    readonly filterCounts?: { readonly all: number; readonly overdue: number; readonly due_soon: number; readonly without_active: number };
}

export interface CustomerLayawayRow {
    readonly code: string;
    readonly url: string;
    readonly status: string;
    readonly statusLabel: string;
    readonly productName: string;
    readonly catalogName: string | null;
    readonly total: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly nextDueDate: string | null;
    readonly createdAt: string | null;
    readonly cancellationReason: string | null;
    readonly cancelledBy: string | null;
    readonly resendCodeUrl: string | null;
}

export interface CustomerReceiptRow {
    readonly id: number;
    readonly url: string;
    readonly submittedAt: string | null;
    readonly layawayCode: string | null;
    readonly statusLabel: string;
    readonly declared: string;
    readonly paidToAccount: string | null;
    readonly rejectionReason: string | null;
    readonly reviewedBy: string | null;
    readonly fileUrl: string;
}

export interface MergeCandidate {
    readonly id: number;
    readonly name: string;
    readonly document: string;
    readonly phone: string;
    readonly moves: {
        readonly layaways: number;
        readonly receipts: number;
        readonly login_tokens: number;
        readonly account_moves: boolean;
        readonly messages?: number;
        readonly telemetry_events?: number;
    };
    readonly blocked: boolean;
}

export interface CustomerShowProps {
    readonly customer: {
        readonly id: number;
        readonly name: string;
        readonly document: string;
        readonly phone: string;
        readonly phoneLabel: string;
        readonly email: string | null;
        readonly createdAt: string | null;
        readonly termsAcceptedAt: string | null;
        readonly termsDeclaredBy: string | null;
        readonly hasLinkedAccount: boolean;
    };
    readonly totals: {
        readonly committed: MoneyView;
        readonly paid: MoneyView;
        readonly remaining: MoneyView;
        readonly overdueInstallments: number;
        readonly overdueAmount: MoneyView;
        readonly nextDueDate: string | null;
    };
    readonly layaways: {
        readonly active: readonly CustomerLayawayRow[];
        readonly history: readonly CustomerLayawayRow[];
        readonly historyTotal: number;
    };
    readonly lists: { readonly paymentsTotal: number; readonly receiptsTotal: number; readonly moreUrl: string | null };
    readonly payments: readonly (PaymentRow & { readonly layawayCode: string | null })[];
    readonly receipts: readonly CustomerReceiptRow[];
    readonly conversationId: number | null;
    readonly subscriptions: readonly { readonly name: string; readonly since: string | null; readonly muted: boolean }[];
    readonly merge: { readonly url: string; readonly candidatesUrl: string };
    readonly manage: { readonly updateUrl: string; readonly deleteUrl: string; readonly liveLayaways: number };
}
