export interface AnalyticsTotals {
    readonly visits: number;
    readonly visitors: number;
    readonly layaways: number;
}

export interface CatalogAnalytics {
    readonly totals: AnalyticsTotals;
    readonly funnel: readonly { readonly event: string; readonly count: number }[] | null;
    readonly catalogs: readonly { readonly name: string; readonly slug: string; readonly visits: number; readonly layaways: number }[] | null;
    readonly topProducts: readonly { readonly name: string; readonly views: number }[] | null;
    readonly searchesWithoutResults: readonly { readonly term: string; readonly count: number }[] | null;
    readonly monthly: readonly { readonly month: string; readonly visits: number; readonly subscriptions: number }[] | null;
}

export interface AnalyticsPageProps {
    readonly period: number;
    readonly periods: readonly number[];
    readonly full: boolean;
    readonly analytics: CatalogAnalytics;
    readonly catalog?: string | null;
    readonly catalogOptions?: readonly { readonly value: string; readonly label: string }[];
    readonly previous?: { readonly visits: number; readonly visitors: number; readonly layaways: number } | null;
}
