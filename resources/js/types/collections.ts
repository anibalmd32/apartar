import { type TableMeta } from '@/types/data-table';
import { type MoneyView } from '@/types/money';

export interface CollectionCustomer {
    readonly name: string;
    readonly phone: string;
    readonly phoneLabel: string;
    readonly url?: string;
    readonly chatUrl?: string | null;
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
}

export interface CollectionLayawayRow {
    readonly id: number;
    readonly customer: CollectionCustomer | null;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly productName: string;
    readonly completedAt: string | null;
    readonly total: MoneyView;
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
}

export type CollectionIndexProps =
    | (CollectionPropsBase & { readonly kind: 'installments'; readonly rows: readonly CollectionInstallmentRow[] })
    | (CollectionPropsBase & { readonly kind: 'layaways'; readonly rows: readonly CollectionLayawayRow[] });
