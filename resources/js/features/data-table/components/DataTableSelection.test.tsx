import { DataTable } from '@/features/data-table/components/DataTable';
import { useRowSelection } from '@/features/data-table/hooks/useRowSelection';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { get: vi.fn(), on: vi.fn(() => () => undefined) } }));

interface Row {
    id: number;
    name: string;
    open: boolean;
}

const COLUMNS: DataTableColumn<Row>[] = [{ id: 'name', header: 'Nombre', primary: true, cell: (row) => row.name }];

const META: TableMeta = { page: 1, perPage: 25, sort: null, filters: {}, total: 3, lastPage: 1, hasMore: false, ignoredFilters: [] };

const ROWS: Row[] = [
    { id: 1, name: 'Taladro', open: true },
    { id: 2, name: 'Sierra', open: false },
    { id: 3, name: 'Lija', open: true },
];

const getId = (row: Row) => row.id;
const canSelect = (row: Row) => row.open;
const labelOf = (row: Row) => row.name;

function Harness({ rows = ROWS, onIds }: { rows?: Row[]; onIds?: (ids: readonly number[]) => void }) {
    const table = useServerTable<Row>({ rows, meta: META, columns: COLUMNS });
    const selection = useRowSelection({ rows, getId, canSelect, labelOf, disabledHint: 'Está cerrado' });

    onIds?.(selection.ids);

    return (
        <DataTable label="Prueba" table={table} empty="Nada." selection={selection} selectionActions={<button type="button">Hacer algo</button>} />
    );
}

const tableScope = () => screen.getByRole('table');

it('sin selección no hay barra, y las filas que no se pueden elegir tienen la casilla apagada', () => {
    render(<Harness />);

    expect(screen.queryByRole('region', { name: 'Acciones con la selección' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('checkbox', { name: 'Seleccionar Taladro' })[0]).toBeEnabled();
    expect(screen.getAllByRole('checkbox', { name: /Sierra: Está cerrado/ })[0]).toBeDisabled();
});

it('al elegir una fila aparece la barra con el recuento y las acciones', () => {
    render(<Harness />);

    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Seleccionar Taladro' })[0]!);

    const bar = screen.getByRole('region', { name: 'Acciones con la selección' });
    expect(bar).toHaveTextContent('1 seleccionado');
    expect(screen.getByRole('button', { name: 'Hacer algo' })).toBeInTheDocument();
});

it('«seleccionar todos» marca solo las filas elegibles de la página y vuelve a soltarlas', () => {
    let ids: readonly number[] = [];
    render(<Harness onIds={(current) => (ids = current)} />);

    const all = screen.getAllByRole('checkbox', { name: 'Seleccionar todos los de esta página' })[0]!;
    fireEvent.click(all);

    expect(ids).toEqual([1, 3]);
    expect(screen.getByRole('region', { name: 'Acciones con la selección' })).toHaveTextContent('2 seleccionados');
    expect(all).toBeChecked();

    fireEvent.click(all);
    expect(ids).toEqual([]);
});

it('la casilla de la página queda a medias con una selección parcial', () => {
    render(<Harness />);

    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Seleccionar Lija' })[0]!);

    expect((screen.getAllByRole('checkbox', { name: 'Seleccionar todos los de esta página' })[0] as HTMLInputElement).indeterminate).toBe(true);
    expect(tableScope().querySelector('tr.bg-primary\\/5')).not.toBeNull();
});

it('«quitar selección» la vacía y esconde la barra', () => {
    render(<Harness />);

    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Seleccionar Taladro' })[0]!);
    fireEvent.click(screen.getByRole('button', { name: 'Quitar selección' }));

    expect(screen.queryByRole('region', { name: 'Acciones con la selección' })).not.toBeInTheDocument();
});

it('olvida las filas que dejan de estar en pantalla o de ser elegibles', () => {
    let ids: readonly number[] = [];
    const { rerender } = render(<Harness onIds={(current) => (ids = current)} />);

    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Seleccionar todos los de esta página' })[0]!);
    expect(ids).toEqual([1, 3]);

    rerender(<Harness rows={[{ id: 1, name: 'Taladro', open: false }, ROWS[2]!]} onIds={(current) => (ids = current)} />);

    expect(ids).toEqual([3]);
});
