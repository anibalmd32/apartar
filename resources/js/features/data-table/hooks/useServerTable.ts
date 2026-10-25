import { type DataTableColumn, type ServerTableState, type TableMeta } from '@/types/data-table';
import { router } from '@inertiajs/react';
import { type RowData } from '@tanstack/react-table';
import { getCoreRowModel, type LegacyColumnDef, type LegacyReactTable, useLegacyTable } from '@tanstack/react-table/legacy';
import { useCallback, useMemo, useRef, useState } from 'react';

export const PER_PAGE_OPTIONS: readonly number[] = [25, 50, 100];

export interface ServerTableOptions<TRow extends RowData> {
    rows: readonly TRow[];
    meta: TableMeta;
    columns: readonly DataTableColumn<TRow>[];
    only?: readonly string[];
}

export interface ServerTable<TRow extends RowData> {
    table: LegacyReactTable<TRow>;
    columns: readonly DataTableColumn<TRow>[];
    meta: TableMeta;
    state: ServerTableState;
    loading: boolean;
    failed: boolean;
    retry: () => void;
    setPage: (page: number) => void;
    setPerPage: (perPage: number) => void;
    toggleSort: (sortKey: string) => void;
    setSort: (sort: string | null) => void;
    setFilter: (key: string, value: string | string[] | null) => void;
    setFilters: (patch: Readonly<Record<string, string | string[] | null>>) => void;
    clearFilters: () => void;
}

type Query = Record<string, string | string[]>;

export function toQuery(state: ServerTableState): Query {
    const query: Query = {};

    if (state.page > 1) {
        query.page = String(state.page);
    }

    if (state.perPage !== PER_PAGE_OPTIONS[0]) {
        query.per_page = String(state.perPage);
    }

    if (state.sort !== null) {
        query.sort = state.sort;
    }

    for (const [key, value] of Object.entries(state.filters)) {
        if (value !== null && value !== '' && !(Array.isArray(value) && value.length === 0)) {
            query[key] = value;
        }
    }

    return query;
}

export function nextSort(current: string | null, sortKey: string): string | null {
    if (current === sortKey) {
        return `-${sortKey}`;
    }

    return current === `-${sortKey}` ? null : sortKey;
}

export function useServerTable<TRow extends RowData>({ rows, meta, columns, only = ['rows', 'meta'] }: ServerTableOptions<TRow>): ServerTable<TRow> {
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const lastQuery = useRef<Query | null>(null);

    const state = useMemo<ServerTableState>(
        () => ({ page: meta.page, perPage: meta.perPage, sort: meta.sort, filters: meta.filters }),
        [meta.page, meta.perPage, meta.sort, meta.filters],
    );

    const send = useCallback(
        (query: Query) => {
            lastQuery.current = query;

            const offException = router.on('exception', () => setFailed(true));
            const offInvalid = router.on('invalid', (event) => {
                event.preventDefault();
                setFailed(true);
            });

            router.get(window.location.pathname, query, {
                only: [...only],
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    setLoading(true);
                    setFailed(false);
                },
                onFinish: () => {
                    setLoading(false);
                    offException();
                    offInvalid();
                },
            });
        },
        [only],
    );

    const visit = useCallback((changes: Partial<ServerTableState>) => send(toQuery({ ...state, ...changes })), [send, state]);

    const columnDefs = useMemo<LegacyColumnDef<TRow>[]>(
        () =>
            columns.map((column) => ({
                id: column.id,
                header: column.header,
                cell: (context) => column.cell(context.row.original),
                enableSorting: column.sortKey !== undefined,
            })),
        [columns],
    );

    const sorting = meta.sort === null ? [] : [{ id: meta.sort.replace(/^-/, ''), desc: meta.sort.startsWith('-') }];

    const table = useLegacyTable<TRow>({
        data: rows as TRow[],
        columns: columnDefs,
        getCoreRowModel: getCoreRowModel(),
        manualPagination: true,
        manualSorting: true,
        manualFiltering: true,
        pageCount: meta.lastPage ?? -1,
        state: { sorting, pagination: { pageIndex: meta.page - 1, pageSize: meta.perPage } },
    });

    return {
        table,
        columns,
        meta,
        state,
        loading,
        failed,
        retry: () => {
            if (lastQuery.current !== null) {
                send(lastQuery.current);
            }
        },
        setPage: (page) => visit({ page: Math.max(1, Math.trunc(page)) }),
        setPerPage: (perPage) => visit({ page: 1, perPage: PER_PAGE_OPTIONS.includes(perPage) ? perPage : PER_PAGE_OPTIONS[0] }),
        toggleSort: (sortKey) => visit({ page: 1, sort: nextSort(meta.sort, sortKey) }),
        setSort: (sort) => visit({ page: 1, sort }),
        setFilter: (key, value) => visit({ page: 1, filters: { ...state.filters, [key]: value } }),
        setFilters: (patch) => visit({ page: 1, filters: { ...state.filters, ...patch } }),
        clearFilters: () => visit({ page: 1, filters: Object.fromEntries(Object.keys(state.filters).map((key) => [key, null])) }),
    };
}
