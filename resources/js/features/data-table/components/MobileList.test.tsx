import { DataTable } from '@/features/data-table/components/DataTable';
import { useRowSelection } from '@/features/data-table/hooks/useRowSelection';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn(), on: vi.fn(() => () => undefined) },
    Link: ({ href, children, className, ...props }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className} {...props}>
            {children}
        </a>
    ),
}));

interface Row {
    id: number;
    name: string;
    sku: string;
    price: string;
    paid: number;
    draft: boolean;
}

const COLUMNS: DataTableColumn<Row>[] = [
    { id: 'name', header: 'Producto', mobile: { title: (row) => row.name, subtitle: (row) => row.sku }, cell: (row) => row.name },
    { id: 'price', header: 'Precio', mobile: 'amount', cell: (row) => row.price },
    { id: 'state', header: 'Estado', mobile: 'status', cell: (row) => (row.draft ? 'Borrador' : 'Publicado') },
    { id: 'paid', header: 'Pagado', mobile: { meta: (row) => (row.paid === 0 ? null : `Pagado $${row.paid}`) }, cell: (row) => `$${row.paid}` },
    { id: 'category', header: 'Categoría', mobile: 'hidden', cell: () => 'Accesorios' },
];

const META: TableMeta = { page: 1, perPage: 25, sort: null, filters: {}, total: 2, lastPage: 1, hasMore: false, ignoredFilters: [] };

const ROWS: Row[] = [
    { id: 1, name: 'Mochila', sku: 'ACC-1', price: '$25,00', paid: 0, draft: true },
    { id: 2, name: 'Taladro', sku: 'HER-2', price: '$90,00', paid: 10, draft: false },
];

function Harness({ selectable = false }: { selectable?: boolean }) {
    const table = useServerTable<Row>({ rows: ROWS, meta: META, columns: COLUMNS });
    const selection = useRowSelection({ rows: ROWS, getId: (row) => row.id, canSelect: (row) => row.draft, labelOf: (row) => row.name });

    return (
        <DataTable
            label="Inventario"
            table={table}
            empty="Nada."
            rowHref={(row) => `/productos/${row.id}/editar`}
            selection={selectable ? selection : undefined}
        />
    );
}

const cards = () => within(screen.getByRole('list', { name: 'Inventario' })).getAllByRole('listitem');

afterEach(() => vi.useRealTimers());

it('en el celular cada fila es una tarjeta compacta que reparte las columnas por su papel', () => {
    render(<Harness />);

    const [first, second] = cards();

    expect(first).toHaveTextContent('Mochila');
    expect(first).toHaveTextContent('ACC-1');
    expect(first).toHaveTextContent('$25,00');
    expect(first).toHaveTextContent('Borrador');
    expect(first).not.toHaveTextContent('Accesorios');
    expect(first).not.toHaveTextContent('Categoría');
    expect(first).not.toHaveTextContent('Pagado');
    expect(second).toHaveTextContent('Pagado $10');
});

it('la tarjeta entera lleva al detalle con un solo enlace', () => {
    render(<Harness />);

    const links = within(cards()[0]!).getAllByRole('link');

    expect(links).toHaveLength(1);
    expect(links[0]).toHaveAttribute('href', '/productos/1/editar');
    expect(links[0]).toHaveClass('after:absolute', 'after:inset-0');
});

it('las casillas solo aparecen al entrar en modo selección y tocar la tarjeta la marca', () => {
    render(<Harness selectable />);

    expect(within(screen.getByRole('list', { name: 'Inventario' })).queryByRole('checkbox')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Seleccionar' }));

    const first = cards()[0]!;
    expect(within(first).getByRole('checkbox', { name: 'Seleccionar Mochila' })).not.toBeChecked();
    expect(within(first).queryByRole('link')).toBeNull();

    fireEvent.click(within(first).getByText('Mochila'));

    expect(within(first).getByRole('checkbox', { name: 'Seleccionar Mochila' })).toBeChecked();
    expect(screen.getByRole('region', { name: 'Acciones con la selección' })).toHaveTextContent('1 seleccionado');

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(within(screen.getByRole('list', { name: 'Inventario' })).queryByRole('checkbox')).toBeNull();
    expect(screen.queryByRole('region', { name: 'Acciones con la selección' })).toBeNull();
});

it('mantener pulsada una tarjeta elegible entra en modo selección con ella marcada', () => {
    vi.useFakeTimers();
    render(<Harness selectable />);

    fireEvent.pointerDown(cards()[0]!, { button: 0, clientX: 10, clientY: 10 });
    act(() => vi.advanceTimersByTime(600));
    fireEvent.pointerUp(cards()[0]!);

    expect(within(cards()[0]!).getByRole('checkbox', { name: 'Seleccionar Mochila' })).toBeChecked();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeInTheDocument();
});
