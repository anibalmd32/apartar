import { type FeedPreview } from '@/types/account';
import { type OrganizationBrand } from '@/types/backoffice';
import { type RateView } from '@/types/exchange';
import { type DownPaymentProgressView } from '@/types/layaway';
import { type MoneyView } from '@/types/money';
import { type PaymentAccountView } from '@/types/payment';
import { type PaymentRow, type ReceiptRow } from '@/types/receipt';

export type PortalMode = 'account' | 'scoped';

export interface PortalSessionView {
    readonly mode: PortalMode;
    readonly fullName: string;
    readonly businessName: string | null;
    readonly unreadChats: number;
}

export interface PortalAccessProps {
    readonly status?: string | null;
    readonly code?: string | null;
    readonly googleUrl: string;
}

export interface PayableLayaway extends LayawaySummary {
    readonly businessName: string;
    readonly payUrl: string;
}

export interface PortalPayProps {
    readonly layaways: readonly PayableLayaway[];
    readonly dashboardUrl: string;
}

export type LayawayStatus = 'pending_down_payment' | 'active' | 'overdue' | 'completed' | 'cancelled' | 'expired';

export interface LayawaySummary {
    readonly code: string;
    readonly status: LayawayStatus;
    readonly statusLabel: string;
    readonly isTerminal: boolean;
    readonly acceptsPayments: boolean;
    readonly productName: string;
    readonly quantity: number;
    readonly total: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly nextDueDate: string | null;
    readonly nextDueDateLabel: string | null;
    readonly overdueInstallments: number;
    readonly nextAmount?: MoneyView | null;
    readonly overdueDays?: number | null;
    readonly imageUrl?: string | null;
}

export interface PortalTotals {
    readonly activeLayaways: number;
    readonly committed: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly overdueInstallments: number;
    readonly nextDueDate: string | null;
    readonly nextDueDateLabel: string | null;
}

export interface BusinessBlock {
    readonly business: OrganizationBrand;
    readonly closed: boolean;
    readonly rate: RateView;
    readonly totals: PortalTotals;
    readonly layaways: readonly LayawaySummary[];
    readonly history: readonly LayawaySummary[];
}

export type PortalAlert = { readonly actionUrl: string } & (
    | {
          readonly kind: 'down_payment_window';
          readonly hoursLeft: number;
          readonly minutesLeft: number;
          readonly layawayCode: string;
          readonly businessName: string;
      }
    | { readonly kind: 'overdue'; readonly count: number; readonly amount: MoneyView; readonly businessName: string | null }
    | {
          readonly kind: 'due_soon';
          readonly dueDate: string;
          readonly dueDateLabel: string;
          readonly amount: MoneyView;
          readonly businessName: string;
      }
    | { readonly kind: 'receipt_pending'; readonly count: number }
    | { readonly kind: 'receipt_rejected'; readonly layawayCode: string; readonly businessName: string }
    | { readonly kind: 'claim_available'; readonly businessName: string }
);

export interface PortalDashboardProps {
    readonly session: { readonly mode: PortalMode; readonly displayName: string };
    readonly businesses: readonly BusinessBlock[];
    readonly totals: PortalTotals | null;
    readonly alerts: readonly PortalAlert[];
    readonly connectGoogleUrl: string | null;
    readonly subscriptionsUrl: string | null;
    readonly claimUrl: string | null;
    readonly feedPreview: FeedPreview | null;
    readonly push: { readonly publicKey: string | null; readonly subscribeUrl: string; readonly hasEmail: boolean };
}

export interface PortalClaimProps {
    readonly storeUrl: string;
}

export type InstallmentTone = 'paid' | 'overdue' | 'soon' | 'pending';

export interface InstallmentRow {
    readonly number: number;
    readonly dueDate: string;
    readonly dueDateLabel: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly pending: MoneyView;
    readonly status: 'pending' | 'partially_paid' | 'paid' | 'overdue';
    readonly daysUntilDue: number;
    readonly tone: InstallmentTone;
}

export interface NextPaymentSuggestion {
    readonly kind: 'down_payment' | 'installment';
    readonly installmentNumber: number | null;
    readonly amount: MoneyView;
    readonly started: boolean;
    readonly payOff: MoneyView;
}

export interface PortalLayawayShowProps {
    readonly business: OrganizationBrand;
    readonly layaway: LayawaySummary & {
        readonly downPayment: MoneyView;
        readonly downPaymentProgress: DownPaymentProgressView | null;
        readonly expiresAt: string | null;
        readonly activatedAt: string | null;
        readonly statusMessage: string | null;
    };
    readonly installments: readonly InstallmentRow[];
    readonly nextPaymentSuggestion: NextPaymentSuggestion | null;
    readonly paymentAccounts: readonly PaymentAccountView[];
    readonly catalogUrl: string | null;
    readonly receiptUrl: string | null;
    readonly payments: readonly PaymentRow[];
    readonly receipts: readonly ReceiptRow[];
}

export interface PortalLoginLinkProps {
    readonly token: string;
    readonly preview?: { readonly businessName: string; readonly document: string | null } | null;
}

export interface PortalLoginLinkUnusableProps {
    readonly message: string;
}
