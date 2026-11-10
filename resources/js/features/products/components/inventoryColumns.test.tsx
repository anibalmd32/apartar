import { cilArchive } from '@/components/ui/archive-icon';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { ArchiveToggle } from '@/features/products/components/ArchiveToggle';
import { inventoryColumns } from '@/features/products/components/inventoryColumns';
import { centsUsd } from '@/lib/money';
import { type TableMeta } from '@/types/data-table';
import { type ProductListItem } from '@/types/product';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
    router: { get: vi.fn(), post: vi.fn(), on: vi.fn(() => () => undefined) },
    Link: ({ href, children, className, ...props }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className} {...props}>
            {children}
        </a>
    ),
}));

const META: TableMeta = { page: 1, perPage: 25, sort: null, filters: {}, total: 1, lastPage: 1, hasMore: false, ignoredFilters: [] };

const PRODUCT: ProductListItem = {
    id: 7,
    name: 'Lentes de sol polarizados',
    sku: 'ACC-006',
    status: 'published',
    stock: 14,
    reservedUnits: 1,
    shortfallUnits: 0,
    price: { cents: centsUsd(1_900), usd: '$19,00', ves: null },
    categoryName: 'Accesorios',
    catalogsCount: 1,
    scheduledFor: null,
    updatedAt: '12 de oct. de 2026 10:00',
    updatedBy: 'Valentina Rojas',
};

const COLUMNS = inventoryColumns({ draft: 'Borrador', published: 'Publicado', archived: 'Archivado' });

function Inventory() {
    const table = useServerTable({ rows: [PRODUCT], meta: META, columns: COLUMNS });

    return <DataTable label="Inventario" table={table} empty="Nada." rowHref={(product) => `/productos/${product.id}/editar`} />;
}

it('en el celular el producto muestra foto, nombre, precio, stock y estado, sin categoría ni última edición', () => {
    render(<Inventory />);

    const card = within(screen.getByRole('list', { name: 'Inventario' })).getByRole('listitem');

    expect(within(card).getByRole('link', { name: 'Lentes de sol polarizados' })).toHaveAttribute('href', '/productos/7/editar');
    expect(card).toHaveTextContent('$19,00');
    expect(card).toHaveTextContent('Publicado');
    expect(card).toHaveTextContent('14 disp. · 1 apartada');
    expect(card).not.toHaveTextContent('Accesorios');
    expect(card).not.toHaveTextContent('Valentina Rojas');
    expect(card).not.toHaveTextContent('Acciones');
    expect(within(card).getByRole('button', { name: 'Acciones de Lentes de sol polarizados' })).toHaveClass('max-md:size-11');
});

it('archivar usa el icono de archivo, no la papelera', () => {
    render(<Inventory />);

    fireEvent.click(within(screen.getByRole('table')).getByRole('button', { name: 'Acciones de Lentes de sol polarizados' }));

    expect(screen.getByRole('menuitem', { name: 'Archivar' }).querySelector('svg')).toHaveAttribute('viewBox', `0 0 ${cilArchive[0]}`);
});

it('el botón de archivar del producto también usa el icono de archivo', () => {
    render(<ArchiveToggle productId={7} archived={false} />);

    expect(screen.getByRole('button', { name: 'Archivar producto' }).querySelector('svg')).toHaveAttribute('viewBox', `0 0 ${cilArchive[0]}`);
});

it('marca los productos con fotos en revisión o rechazadas', () => {
    function Reviewed() {
        const table = useServerTable({
            rows: [
                { ...PRODUCT, id: 8, status: 'draft', photoReview: 'pending' },
                { ...PRODUCT, id: 9, status: 'draft', name: 'Paisaje', photoReview: 'rejected' },
            ] satisfies ProductListItem[],
            meta: { ...META, total: 2 },
            columns: COLUMNS,
        });

        return <DataTable label="Inventario" table={table} empty="Nada." rowHref={(product) => `/productos/${product.id}/editar`} />;
    }

    render(<Reviewed />);

    const [pending, rejected] = within(screen.getByRole('list', { name: 'Inventario' })).getAllByRole('listitem');

    expect(pending).toHaveTextContent('Fotos en revisión');
    expect(rejected).toHaveTextContent('Fotos rechazadas');
});
