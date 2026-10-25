import { nextSort, toQuery, useServerTable } from '@/features/data-table/hooks/useServerTable';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { get, on, handlers } = vi.hoisted(() => {
    const handlers: Record<string, ((event: { preventDefault: () => void }) => void) | undefined> = {};

    return {
        handlers,
        get: vi.fn(),
        on: vi.fn((name: string, handler: (event: { preventDefault: () => void }) => void) => {
            handlers[name] = handler;

            return () => {
                handlers[name] = undefined;
            };
        }),
    };
});

vi.mock('@inertiajs/react', () => ({ router: { get, on } }));

interface Row {
    id: number;
    name: string;
}

const COLUMNS: DataTableColumn<Row>[] = [
    { id: 'name', header: 'Nombre', sortKey: 'name', cell: (row) => row.name },
    { id: 'id', header: 'Id', cell: (row) => row.id },
];

function meta(overrides: Partial<TableMeta> = {}): TableMeta {
    return {
        page: 2,
        perPage: 25,
        sort: '-created',
        filters: { status: 'draft', category: null },
        total: 60,
        lastPage: 3,
        hasMore: true,
        ignoredFilters: [],
        ...overrides,
    };
}

function hook(current: TableMeta = meta()) {
    return renderHook(() => useServerTable<Row>({ rows: [{ id: 1, name: 'Taladro' }], meta: current, columns: COLUMNS }));
}

function lastVisit(): { query: Record<string, unknown>; options: Record<string, unknown> } {
    const call = get.mock.calls.at(-1) as [string, Record<string, unknown>, Record<string, unknown>];

    return { query: call[1], options: call[2] };
}

beforeEach(() => {
    get.mockReset();
});

it('pide solo rows y meta, con preserveState y preserveScroll', () => {
    const { result } = hook();

    act(() => result.current.setPage(3));

    expect(lastVisit().options).toMatchObject({ only: ['rows', 'meta'], preserveState: true, preserveScroll: true });
});

it('conserva los filtros y el orden al cambiar de página', () => {
    const { result } = hook();

    act(() => result.current.setPage(3));

    expect(lastVisit().query).toEqual({ page: '3', sort: '-created', status: 'draft' });
});

it('vuelve a la primera página al cambiar un filtro, y quita los vacíos', () => {
    const { result } = hook();

    act(() => result.current.setFilter('category', '7'));
    expect(lastVisit().query).toEqual({ sort: '-created', status: 'draft', category: '7' });

    act(() => result.current.clearFilters());
    expect(lastVisit().query).toEqual({ sort: '-created' });
});

it('solo pide tamaños de página que el servidor acepta', () => {
    const { result } = hook();

    act(() => result.current.setPerPage(50));
    expect(lastVisit().query).toMatchObject({ per_page: '50' });

    act(() => result.current.setPerPage(5000));
    expect(lastVisit().query).not.toHaveProperty('per_page');
});

describe('el orden', () => {
    it('va de ascendente a descendente y vuelve al defecto', () => {
        expect(nextSort(null, 'name')).toBe('name');
        expect(nextSort('name', 'name')).toBe('-name');
        expect(nextSort('-name', 'name')).toBeNull();
        expect(nextSort('-created', 'name')).toBe('name');
    });

    it('pulsar una columna pide su orden desde la primera página', () => {
        const { result } = hook();

        act(() => result.current.toggleSort('name'));

        expect(lastVisit().query).toEqual({ sort: 'name', status: 'draft' });
    });

    it('se puede pedir un orden concreto, y volver al defecto', () => {
        const { result } = hook();

        act(() => result.current.setSort('-name'));
        expect(lastVisit().query).toEqual({ sort: '-name', status: 'draft' });

        act(() => result.current.setSort(null));
        expect(lastVisit().query).toEqual({ status: 'draft' });
    });
});

it('no ordena en el cliente: pinta las filas como llegan', () => {
    const { result } = renderHook(() =>
        useServerTable<Row>({
            rows: [
                { id: 1, name: 'Zeta' },
                { id: 2, name: 'Alfa' },
            ],
            meta: meta({ sort: 'name' }),
            columns: COLUMNS,
        }),
    );

    expect(result.current.table.getRowModel().rows.map((row) => row.original.name)).toEqual(['Zeta', 'Alfa']);
    expect(result.current.table.getState().sorting).toEqual([{ id: 'name', desc: false }]);
});

it('marca el fallo de red y reintenta la última petición', () => {
    const { result } = hook();

    act(() => result.current.setPage(3));
    act(() => handlers.exception?.({ preventDefault: () => undefined }));

    expect(result.current.failed).toBe(true);
    expect(result.current.table.getRowModel().rows).toHaveLength(1);

    act(() => result.current.retry());

    expect(get).toHaveBeenCalledTimes(2);
    expect(lastVisit().query).toEqual({ page: '3', sort: '-created', status: 'draft' });
});

it('una respuesta que no es de Inertia tampoco abre el modal', () => {
    const { result } = hook();
    const preventDefault = vi.fn();

    act(() => result.current.setPage(3));
    act(() => handlers.invalid?.({ preventDefault }));

    expect(preventDefault).toHaveBeenCalled();
    expect(result.current.failed).toBe(true);
});

it('compone la query string sin los valores por defecto', () => {
    expect(toQuery({ page: 1, perPage: 25, sort: null, filters: { q: '', tags: [], status: null } })).toEqual({});
    expect(toQuery({ page: 4, perPage: 100, sort: '-price', filters: { tags: ['1', '2'] } })).toEqual({
        page: '4',
        per_page: '100',
        sort: '-price',
        tags: ['1', '2'],
    });
});
