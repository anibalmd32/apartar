import { type TableMeta } from '@/types/data-table';
import { type MoneyView } from '@/types/money';

export interface CollectionCustomer {
    readonly name: string;
    readonly phone: string | null;
    readonly phoneLabel: string | null;
    readonly url?: string;
    readonly chatUrl?: string | null;
}

export interface CollectionDelivery {
    readonly deliveredAt: string | null;
    readonly url: string | null;
}

export interface CollectionInstallmentRow {
    readonly id: number;
    readonly customer: CollectionCustomer | null;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly number: number;
    readonly of?: number;
    readonly dueDate: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    readonly daysLate: number;
    readonly pending: MoneyView;
    readonly productName: string;
    readonly delivery: CollectionDelivery;
}

export interface CollectionLayawayGroup {
    readonly id: number;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly productName: string;
    readonly paid: MoneyView;
    readonly total: MoneyView;
    readonly progress: number;
    readonly owed: MoneyView;
    readonly installments: number;
    readonly of?: number;
    readonly nextDue: string;
    readonly daysLate: number;
    readonly delivery: CollectionDelivery;
}

export interface CollectionCustomerRow {
    readonly id: number;
    readonly customer: CollectionCustomer | null;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly installments: readonly CollectionInstallmentRow[];
    readonly layawayRows: readonly CollectionLayawayGroup[];
    readonly count: number;
    readonly layaways: number;
    readonly pending: MoneyView;
    readonly paid: MoneyView;
    readonly total: MoneyView;
    readonly progress: number;
    readonly daysLate: number;
}

export interface CollectionLayawayRow {
    readonly id: number;
    readonly customer: CollectionCustomer | null;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly productName: string;
    readonly completedAt: string | null;
    readonly total: MoneyView;
    readonly delivery: CollectionDelivery;
}

export interface CollectionDownPaymentRow {
    readonly id: number;
    readonly customer: CollectionCustomer | null;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly productName: string;
    readonly downPayment: MoneyView;
    readonly missing: MoneyView;
    readonly expiresAt: string | null;
    readonly delivery: CollectionDelivery;
}

export interface CollectionFiltersState {
    readonly preset: string | null;
    readonly from: string | null;
    readonly to: string | null;
    readonly status: string | null;
    readonly q: string | null;
    readonly category: string | null;
    readonly catalog: string | null;
}

interface Option {
    readonly value: string;
    readonly label: string;
}

export interface CollectionOptions {
    readonly presets: readonly Option[];
    readonly statuses: readonly Option[];
    readonly categories: readonly Option[];
    readonly catalogs: readonly Option[];
}

interface CollectionPropsBase {
    readonly meta: TableMeta;
    readonly definition: string | null;
    readonly options: CollectionOptions;
    readonly summary?: { readonly installments: number; readonly pending: MoneyView; readonly customers: number } | null;
    readonly presetCounts?: Readonly<Record<string, number>>;
    readonly awaitingDownPayment?: { readonly count: number; readonly missing: MoneyView } | null;
    readonly grouped?: boolean;
    readonly detail?: 'layaways' | 'installments';
    readonly groupingUrl?: string | null;
}

export type CollectionIndexProps =
    | (CollectionPropsBase & { readonly kind: 'installments'; readonly rows: readonly CollectionInstallmentRow[] })
    | (CollectionPropsBase & { readonly kind: 'customers'; readonly rows: readonly CollectionCustomerRow[] })
    | (CollectionPropsBase & { readonly kind: 'layaways'; readonly rows: readonly CollectionLayawayRow[] })
    | (CollectionPropsBase & { readonly kind: 'down_payments'; readonly rows: readonly CollectionDownPaymentRow[] });
