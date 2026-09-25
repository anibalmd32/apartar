import { DataTable } from '@/features/data-table/components/DataTable';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * La tabla y sus estados (`REQ-BOF-045` RB-4, RB-6, RB-7, RB-8, SP-4, SP-5).
 */

const { get, on } = vi.hoisted(() => ({ get: vi.fn(), on: vi.fn(() => () => undefined) }));

vi.mock('@inertiajs/react', () => ({ router: { get, on } }));

interface Row {
    id: number;
    name: string;
    stock: number;
}

const COLUMNS: DataTableColumn<Row>[] = [
    { id: 'name', header: 'Nombre', sortKey: 'name', primary: true, cell: (row) => row.name },
    { id: 'stock', header: 'Stock', cell: (row) => row.stock },
];

function meta(overrides: Partial<TableMeta> = {}): TableMeta {
    return { page: 1, perPage: 25, sort: 'name', filters: {}, total: 2, lastPage: 1, hasMore: false, ignoredFilters: [], ...overrides };
}

function Harness({ rows, current }: { rows: Row[]; current: TableMeta }) {
    const table = useServerTable<Row>({ rows, meta: current, columns: COLUMNS });

    return <DataTable label="Prueba" table={table} empty="Ningún producto en borrador." filterLabels={{ category: 'categoría' }} />;
}

const ROWS: Row[] = [
    { id: 1, name: 'Taladro', stock: 3 },
    { id: 2, name: 'Sierra', stock: 0 },
];

beforeEach(() => {
    get.mockReset();
});

it('pinta las filas en tabla y en tarjetas apiladas para el móvil (RB-8)', () => {
    render(<Harness rows={ROWS} current={meta()} />);

    expect(screen.getByRole('table')).toHaveClass('hidden', 'md:table');
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getAllByText('Taladro')).toHaveLength(2);
});

it('anuncia el orden vigente y solo deja pulsar las columnas ordenables (RB-4)', () => {
    render(<Harness rows={ROWS} current={meta({ sort: '-name' })} />);

    const header = screen.getByRole('columnheader', { name: /Nombre/ });
    expect(header).toHaveAttribute('aria-sort', 'descending');
    expect(within(screen.getByRole('columnheader', { name: 'Stock' })).queryByRole('button')).toBeNull();

    fireEvent.click(within(header).getByRole('button'));

    expect(get.mock.calls[0]?.[1]).toEqual({});
});

it('sin filas enseña la definición del filtro, no un «sin datos» (RB-7)', () => {
    render(<Harness rows={[]} current={meta({ total: 0 })} />);

    expect(screen.getByRole('status')).toHaveTextContent('Ningún producto en borrador.');
    expect(screen.queryByRole('table')).toBeNull();
});

it('avisa del filtro que el servidor ignoró, por su nombre (SP-4)', () => {
    render(<Harness rows={ROWS} current={meta({ ignoredFilters: ['category'] })} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Un filtro no se pudo aplicar (categoría)');
});

it('con total dice la página de cuántas; sin total, no se inventa uno (RB-6)', () => {
    const { rerender } = render(<Harness rows={ROWS} current={meta({ total: 60, lastPage: 3, page: 2, hasMore: true })} />);

    expect(screen.getByText('Página 2 de 3')).toBeInTheDocument();
    expect(screen.getByText('60 resultados')).toBeInTheDocument();

    rerender(<Harness rows={ROWS} current={meta({ total: null, lastPage: null, page: 2, hasMore: true })} />);

    expect(screen.getByText('Página 2')).toBeInTheDocument();
    expect(screen.queryByText(/resultados/)).toBeNull();
    expect(screen.getByRole('button', { name: 'Siguiente' })).toBeEnabled();
});

it('ofrece solo los tamaños de página que el servidor acepta (RB-5)', () => {
    render(<Harness rows={ROWS} current={meta()} />);

    const options = within(screen.getByRole('combobox'))
        .getAllByRole('option')
        .map((option) => option.textContent);

    expect(options).toEqual(['25', '50', '100']);
});
