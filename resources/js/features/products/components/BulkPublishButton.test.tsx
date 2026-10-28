import { DataTable } from '@/features/data-table/components/DataTable';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { BULK_PUBLISH_URL, BulkPublishButton } from '@/features/products/components/BulkPublishButton';
import { useDraftSelection } from '@/features/products/hooks/useDraftSelection';
import { centsUsd } from '@/lib/money';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { type ProductListItem, type ProductStatus } from '@/types/product';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { post, get: vi.fn(), on: vi.fn(() => () => undefined) } }));

const COLUMNS: DataTableColumn<ProductListItem>[] = [{ id: 'product', header: 'Producto', primary: true, cell: (product) => product.name }];

const META: TableMeta = { page: 1, perPage: 25, sort: null, filters: {}, total: 3, lastPage: 1, hasMore: false, ignoredFilters: [] };

function product(id: number, name: string, status: ProductStatus): ProductListItem {
    return {
        id,
        name,
        sku: `SKU-${id}`,
        status,
        stock: 3,
        reservedUnits: 0,
        shortfallUnits: 0,
        price: { cents: centsUsd(25_000), usd: '$250,00', ves: null },
        categoryName: null,
        catalogsCount: 0,
        scheduledFor: null,
        updatedAt: null,
        updatedBy: null,
    };
}

const ROWS = [product(1, 'Mochila', 'draft'), product(2, 'Taladro', 'published'), product(3, 'Lentes', 'draft'), product(4, 'Sierra', 'archived')];

function Inventory() {
    const table = useServerTable({ rows: ROWS, meta: META, columns: COLUMNS });
    const selection = useDraftSelection(ROWS);

    return (
        <DataTable
            label="Inventario"
            table={table}
            empty="Nada."
            selection={selection}
            selectionActions={<BulkPublishButton ids={selection.ids} onDone={selection.clear} />}
        />
    );
}

beforeEach(() => post.mockReset());

it('solo deja elegir los borradores', () => {
    render(<Inventory />);

    expect(screen.getAllByRole('checkbox', { name: 'Seleccionar Mochila' })[0]).toBeEnabled();
    expect(screen.getAllByRole('checkbox', { name: 'Seleccionar Lentes' })[0]).toBeEnabled();
    expect(screen.getAllByRole('checkbox', { name: /Taladro: Solo los borradores/ })[0]).toBeDisabled();
    expect(screen.getAllByRole('checkbox', { name: /Sierra: Solo los borradores/ })[0]).toBeDisabled();
});

it('selecciona todos los borradores de la página y los publica de una vez', () => {
    render(<Inventory />);

    expect(screen.queryByRole('button', { name: 'Publicar' })).not.toBeInTheDocument();

    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Seleccionar todos los de esta página' })[0]!);

    expect(screen.getByRole('region', { name: 'Acciones con la selección' })).toHaveTextContent('2 seleccionados');

    fireEvent.click(screen.getByRole('button', { name: 'Publicar' }));

    expect(post).toHaveBeenCalledWith(BULK_PUBLISH_URL, { ids: [1, 3] }, expect.objectContaining({ preserveScroll: true }));
});

it('al terminar bien, vacía la selección', () => {
    post.mockImplementation((_url?: string, _data?: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
    render(<Inventory />);

    fireEvent.click(screen.getAllByRole('checkbox', { name: 'Seleccionar Mochila' })[0]!);
    fireEvent.click(screen.getByRole('button', { name: 'Publicar' }));

    expect(screen.queryByRole('region', { name: 'Acciones con la selección' })).not.toBeInTheDocument();
});
