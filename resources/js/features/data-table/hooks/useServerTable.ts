import { type DataTableColumn, type ServerTableState, type TableMeta } from '@/types/data-table';
import { router } from '@inertiajs/react';
import { type RowData } from '@tanstack/react-table';
import { getCoreRowModel, type LegacyColumnDef, type LegacyReactTable, useLegacyTable } from '@tanstack/react-table/legacy';
import { useCallback, useMemo, useRef, useState } from 'react';

/**
 * Una tabla que pagina, ordena y filtra **en el servidor** (`REQ-BOF-045`).
 *
 * ## `@tanstack/react-table` en modo manual (RB-1)
 *
 * `manualPagination`, `manualSorting` y `manualFiltering`: la librería pinta
 * las filas que llegan y no toca su orden. Si ordenara en el cliente, ordenaría
 * **la página que tiene** —25 filas de 2.000— y el resultado parecería correcto
 * y estaría mal, que es la peor forma de estar mal.
 *
 * ## El estado es el de la URL, y lo manda el servidor (RB-3, SP-6)
 *
 * No hay estado propio de página, orden ni filtros: se leen de `meta`, que el
 * servidor resolvió desde la query string con sus guardas puestas. Cambiar
 * algo es **visitar la misma URL con otra query string**, así que la vista se
 * recarga igual, se comparte igual y el botón «atrás» del navegador la
 * restaura sin que este hook haga nada.
 *
 * Cada visita es una recarga parcial que pide **solo `rows` y `meta`** (RB-2),
 * con `preserveState` y `preserveScroll`; las opciones de los filtros no se
 * vuelven a pedir. Un segundo clic rápido cancela la primera petición, que es
 * lo que Inertia hace solo (SP-7).
 *
 * ## Un fallo de red no vacía la tabla (SP-5)
 *
 * Inertia no avisa de un fallo de red en los *callbacks* de la visita, sino con
 * los eventos globales `exception` e `invalid`; el hook los escucha **solo
 * mientras dura su propia visita**. Las filas anteriores se quedan donde
 * estaban, y `retry()` repite la última petición.
 */

/** Los tamaños de página que acepta el servidor (RB-5). El primero es el defecto. */
export const PER_PAGE_OPTIONS: readonly number[] = [25, 50, 100];

export interface ServerTableOptions<TRow extends RowData> {
    rows: readonly TRow[];
    meta: TableMeta;
    columns: readonly DataTableColumn<TRow>[];
    /** Las props que recarga cada visita. Por defecto, las del contrato. */
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
    /** Un orden concreto —`"name"`, `"-name"` o `null` para el defecto—, desde la página 1. */
    setSort: (sort: string | null) => void;
    setFilter: (key: string, value: string | string[] | null) => void;
    clearFilters: () => void;
}

type Query = Record<string, string | string[]>;

/** El estado como query string, sin lo que ya es el defecto. */
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

/** El siguiente orden al pulsar una columna: ascendente, descendente, y vuelta al defecto. */
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
                // Una respuesta que no es de Inertia —un `500` en HTML— no
                // abre el modal por encima de la tabla: se dice aquí, con
                // reintento, y la tabla anterior se queda.
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
        clearFilters: () => visit({ page: 1, filters: Object.fromEntries(Object.keys(state.filters).map((key) => [key, null])) }),
    };
}
