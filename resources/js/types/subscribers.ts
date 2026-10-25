export interface SubscriberRow {
    readonly displayName: string;
    readonly avatarUrl: string | null;
    readonly subscribedAt: string;
    readonly catalogName: string;
    readonly isCustomer: boolean;
    readonly customerUrl: string | null;
}

export interface SubscriberStats {
    readonly total: number;
    readonly people: number | null;
    readonly last7Days: number | null;
    readonly byCatalog: readonly { readonly catalogName: string; readonly count: number }[] | null;
    readonly previous7Days?: number | null;
    readonly customers?: number | null;
    readonly weekly?: readonly number[] | null;
}

export interface SubscribersPageProps {
    readonly subscribers: readonly SubscriberRow[];
    readonly nextPageUrl: string | null;
    readonly stats: SubscriberStats;
}
