import { type MoneyView } from '@/types/money';

export type InvoiceStatus = 'pending' | 'under_review' | 'paid' | 'overdue' | 'void';

export interface InvoiceRow {
    readonly id: number;
    readonly number: string;
    readonly planName: string;
    readonly kind: string;
    readonly periodStart: string;
    readonly periodEnd: string;
    readonly amount: MoneyView;
    readonly outstanding: MoneyView | null;
    readonly status: InvoiceStatus;
    readonly dueDate: string;
    readonly receiptStatus: 'none' | 'pending_review' | 'rejected';
    readonly rejectionReason: string | null;
    readonly payUrl: string | null;
}

export interface PaymentMethodOption {
    readonly value: string;
    readonly label: string;
    readonly requiresReference: boolean;
    readonly defaultCurrency: string;
}

export interface CurrencyOption {
    readonly value: string;
    readonly label: string;
}

export interface InvoicesPageProps {
    readonly invoices: readonly InvoiceRow[];
    readonly methods: readonly PaymentMethodOption[];
    readonly currencies: readonly CurrencyOption[];
    readonly file: { readonly maxMb: number; readonly accept: string };
    readonly subscriptionUrl: string;
    readonly platformAccounts: readonly PlatformAccount[];
}

export interface PlatformAccount {
    readonly method: string;
    readonly detail: string;
}
