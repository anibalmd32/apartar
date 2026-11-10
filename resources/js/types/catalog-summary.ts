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
    readonly parallel?: MoneyView | null;
}

export interface SummaryPreset {
    readonly key: string;
    readonly label: string;
    readonly from: string;
    readonly to: string;
}

export interface SummaryPeriod {
    readonly from: string;
    readonly to: string;
    readonly preset: string | null;
    readonly granularity: 'day' | 'month';
    readonly presets: readonly SummaryPreset[];
}

export interface SummaryPeriodTotals {
    readonly collected: MoneyView;
    readonly payments: number;
    readonly average: MoneyView | null;
    readonly layaways: number;
}

export interface SummarySeriesPoint {
    readonly key: string;
    readonly label: string;
    readonly collected: MoneyView;
    readonly payments: number;
    readonly layaways: number;
    readonly parallel: MoneyView | null;
}

export interface SummarySafeguard {
    readonly parallel: MoneyView;
    readonly gap: MoneyView;
    readonly payments: number;
    readonly recorded: number;
    readonly withoutRate: number;
    readonly byDestination: readonly { readonly label: string; readonly amount: MoneyView; readonly payments: number; readonly recorded: boolean }[];
}

export interface SummaryPaymentRow {
    readonly id: number;
    readonly date: string;
    readonly customer: string | null;
    readonly product: string;
    readonly layawayCode: string;
    readonly layawayUrl: string;
    readonly amount: MoneyView;
    readonly declared: string;
    readonly parallel: string | null;
    readonly safeguard: string | null;
}

export interface CatalogSummaryProps {
    readonly catalog: { readonly id: number; readonly name: string; readonly editUrl: string; readonly url: string };
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
    readonly debtors: readonly { readonly name: string; readonly url: string; readonly layaways: number; readonly remaining: MoneyView }[];
    readonly period: SummaryPeriod;
    readonly periodTotals: SummaryPeriodTotals;
    readonly series: readonly SummarySeriesPoint[];
    readonly safeguard: SummarySafeguard | null;
    readonly payments: readonly SummaryPaymentRow[];
    readonly paymentsTruncated: boolean;
}
