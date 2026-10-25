import { type ReactNode } from 'react';

export interface ServerTableState {
    readonly page: number;
    readonly perPage: number;
    readonly sort: string | null;
    readonly filters: Readonly<Record<string, string | string[] | null>>;
}

export interface TableMeta extends ServerTableState {
    readonly total: number | null;
    readonly lastPage: number | null;
    readonly hasMore: boolean;
    readonly ignoredFilters: readonly string[];
}

export interface Paginated<T> {
    readonly rows: readonly T[];
    readonly meta: TableMeta;
}

export interface DataTableColumn<TRow> {
    readonly id: string;
    readonly header: string;
    readonly cell: (row: TRow) => ReactNode;
    readonly sortKey?: string;
    readonly primary?: boolean;
    readonly align?: 'start' | 'end';
}
