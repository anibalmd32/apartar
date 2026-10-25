import { type OrganizationBrand } from '@/types/backoffice';
import { type Paginated } from '@/types/data-table';
import { type Currency, type MoneyView } from '@/types/money';
import { type PaymentAccountView, type PaymentMethod } from '@/types/payment';
import { type LayawaySummary } from '@/types/portal';

export type ReceiptLayawaySummary = Pick<LayawaySummary, 'code' | 'productName' | 'statusLabel' | 'remaining'>;

export interface ReceiptSuggestion {
    readonly kind: 'down_payment' | 'installment';
    readonly installmentNumber: number | null;
    readonly amount: MoneyView;
    readonly amountUsd: string;
    readonly amountVes: string | null;
}

export interface ReceiptMethodOption {
    readonly value: PaymentMethod;
    readonly label: string;
    readonly isCash: boolean;
    readonly requiresReference: boolean;
    readonly defaultCurrency: Currency;
}

export interface ReceiptCurrencyOption {
    readonly value: Currency;
    readonly label: string;
}

export interface ReceiptCreateProps {
    readonly business: OrganizationBrand;
    readonly layaway: ReceiptLayawaySummary;
    readonly suggestion: ReceiptSuggestion | null;
    readonly paymentAccounts: readonly PaymentAccountView[];
    readonly methods: readonly ReceiptMethodOption[];
    readonly currencies: readonly ReceiptCurrencyOption[];
    readonly paidAt: { readonly min: string; readonly max: string };
    readonly file: { readonly maxMb: number; readonly accept: string };
    readonly storeUrl: string;
    readonly backUrl: string;
    readonly replaces: { readonly submittedAt: string; readonly reason: string | null } | null;
}

export interface ReceiptFormValues {
    [key: string]: string | number | File | null;
    paymentAccount: number | null;
    method: PaymentMethod | '';
    bank: string;
    reference: string;
    paidAt: string;
    amount: string;
    currency: Currency;
    note: string;
    file: File | null;
}

export type ReceiptStatus = 'pending_review' | 'approved' | 'rejected';

export interface ReceiptInboxRow {
    readonly id: number;
    readonly reviewUrl: string;
    readonly status: ReceiptStatus;
    readonly statusLabel: string;
    readonly overdue: boolean;
    readonly submittedAt: string;
    readonly paidAt: string;
    readonly layaway: { readonly code: string; readonly url: string; readonly cancelled: boolean } | null;
    readonly customer: { readonly fullName: string; readonly documentNumber: string } | null;
    readonly method: PaymentMethod;
    readonly methodLabel: string;
    readonly bank: string | null;
    readonly reference: string | null;
    readonly paymentAccount: PaymentAccountView | null;
    readonly declaredAmount: string;
    readonly declaredCurrency: Currency;
    readonly file: { readonly url: string; readonly kind: 'image' | 'pdf' };
}

export interface ReceiptInboxFilters {
    readonly status: ReceiptStatus | null;
    readonly method: PaymentMethod | null;
    readonly bank: string | null;
    readonly account: string | null;
    readonly from: string | null;
    readonly to: string | null;
    readonly cancelled: '1' | null;
}

export interface ReceiptInboxOption {
    readonly value: string;
    readonly label: string;
}

export interface ReceiptInboxProps extends Paginated<ReceiptInboxRow> {
    readonly newestPending: number | null;
    readonly now: string;
    readonly statusCounts?: Readonly<Partial<Record<ReceiptStatus, number>>>;
    readonly options: {
        readonly statuses: readonly ReceiptInboxOption[];
        readonly methods: readonly ReceiptInboxOption[];
        readonly banks: readonly string[];
        readonly paymentAccounts: readonly PaymentAccountView[];
    };
    readonly overdueAfterHours: number;
    readonly timezone: string;
}

export interface PaymentRow {
    readonly settledAt: string;
    readonly amountUsd: MoneyView;
    readonly declaredAmount: string;
    readonly rateApplied: string | null;
    readonly rateSource: 'bcv' | 'parallel' | 'manual';
    readonly rateSourceLabel: string;
    readonly method: string;
    readonly appliedTo: readonly number[];
    readonly downPayment: string | null;
    readonly unallocated: string | null;
    readonly registeredByBusiness: boolean;
    readonly note: string | null;
}

export interface ApprovalPreviewView {
    readonly amount: string;
    readonly rate: string;
    readonly rateSource: string;
    readonly rateSourceLabel: string;
    readonly downPayment: string | null;
    readonly allocations: readonly { readonly number: number; readonly amount: string; readonly covers: boolean }[];
    readonly unallocated: string | null;
    readonly unallocatedCents: number;
    readonly staleRate: { readonly token: string; readonly days: number; readonly sourceLabel: string } | null;
    readonly statusAfter: string;
    readonly statusAfterLabel: string;
    readonly summary: string;
}

export interface ReviewInstallment {
    readonly number: number;
    readonly dueDateLabel: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly status: string;
    readonly statusLabel: string;
}

export interface ReceiptReviewProps {
    readonly receipt: {
        readonly id: number;
        readonly status: ReceiptStatus;
        readonly statusLabel: string;
        readonly submittedAt: string | null;
        readonly paidAt: string;
        readonly method: string;
        readonly bank: string | null;
        readonly reference: string | null;
        readonly note: string | null;
        readonly declaredAmount: string;
        readonly declaredCurrency: Currency;
        readonly declared: string;
        readonly paymentAccount: PaymentAccountView | null;
        readonly file: { readonly url: string; readonly kind: 'image' | 'pdf' };
        readonly reviewedBy: string | null;
        readonly rejectionReason: string | null;
        readonly replacedBy: { readonly url: string; readonly submittedAt: string | null } | null;
        readonly replaces: { readonly url: string; readonly submittedAt: string | null; readonly rejectionReason: string | null } | null;
    };
    readonly customer: { readonly fullName: string; readonly documentNumber: string; readonly phone: string } | null;
    readonly layaway: {
        readonly code: string;
        readonly url: string;
        readonly status: string;
        readonly statusLabel: string;
        readonly productName: string;
        readonly total: MoneyView;
        readonly downPayment: MoneyView;
        readonly paid: MoneyView;
        readonly remaining: MoneyView;
    };
    readonly queue?: {
        readonly position: number;
        readonly total: number;
        readonly previousUrl: string | null;
        readonly nextUrl: string | null;
    } | null;
    readonly installments: readonly ReviewInstallment[];
    readonly payments: readonly PaymentRow[];
    readonly rate: { readonly rate: string; readonly sourceLabel: string; readonly publishedAt: string } | null;
    readonly preview: ApprovalPreviewView | null;
    readonly previewError: string | null;
    readonly approval: {
        readonly url: string;
        readonly previewUrl: string;
        readonly currencies: readonly ReceiptCurrencyOption[];
    } | null;
    readonly rejection: {
        readonly url: string;
        readonly codes: readonly { readonly value: string; readonly label: string }[];
    } | null;
    readonly inboxUrl: string;
}

export interface ReceiptRow {
    readonly id: string;
    readonly submittedAt: string;
    readonly method: string;
    readonly reference: string | null;
    readonly declaredAmount: string;
    readonly paidToAccount: string | null;
    readonly status: ReceiptStatus;
    readonly statusLabel: string;
    readonly rejectionReason: string | null;
    readonly fileUrl: string;
    readonly replacesReceiptId: string | null;
}
