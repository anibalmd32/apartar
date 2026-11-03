import { type MoneyView } from '@/types/money';

export interface CatalogSummaryProduct {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly url: string;
    readonly price: MoneyView;
    readonly stock: number;
    readonly reserved: number;
    readonly sold: number;
    readonly committed: MoneyView;
    readonly paid: MoneyView;
    readonly remaining: MoneyView;
    readonly progress: number;
}

export interface CatalogSummaryProps {
    readonly catalog: { readonly id: number; readonly name: string; readonly editUrl: string };
    readonly totals: {
        readonly layaways: number;
        readonly units: number;
        readonly committed: MoneyView;
        readonly paid: MoneyView;
        readonly remaining: MoneyView;
        readonly progress: number;
        readonly overdue: MoneyView;
        readonly overdueLayaways: number;
        readonly upcoming: MoneyView;
        readonly delivered: number;
        readonly awaitingDelivery: number;
    };
    readonly statuses: Readonly<Record<string, number>>;
    readonly products: readonly CatalogSummaryProduct[];
    readonly collected: readonly { readonly month: string; readonly amount: MoneyView }[];
    readonly debtors: readonly { readonly name: string; readonly url: string; readonly layaways: number; readonly remaining: MoneyView }[];
}
