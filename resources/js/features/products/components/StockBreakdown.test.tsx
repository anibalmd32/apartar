import { StockBreakdown } from '@/features/products/components/StockBreakdown';
import { inventoryColumns, StockCell } from '@/features/products/components/inventoryColumns';
import { centsUsd } from '@/lib/money';
import { type ProductListItem } from '@/types/product';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({ Link: (await import('@/test/fakes')).FakeLink, router: { post: vi.fn() } }));

it('no dice nada de un producto sin apartados', () => {
    const { container } = render(<StockBreakdown breakdown={{ available: 5, reserved: 0, shortfall: 0 }} onHand={5} />);

    expect(container).toBeEmptyDOMElement();
});

it('dice cuántas están apartadas y cuántas quedan para apartar', () => {
    render(<StockBreakdown breakdown={{ available: 3, reserved: 2, shortfall: 0 }} onHand={5} />);

    expect(screen.getByText(/2 unidades en apartados vigentes/)).toBeInTheDocument();
    expect(screen.getByText(/3 unidades disponibles para apartar/)).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();
});

it('avisa de las que faltan cuando se declararon menos de las apartadas', () => {
    render(<StockBreakdown breakdown={{ available: 0, reserved: 3, shortfall: 2 }} onHand={1} />);

    expect(screen.getByText(/ninguna disponible para apartar/)).toBeInTheDocument();

    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('Faltan 2 unidades para cubrir los apartados vigentes');
    expect(alert).toHaveTextContent('tienes 3 unidades apartadas y declaraste 1 unidad en existencias');
    expect(alert).toHaveTextContent('no se cancela ninguno');
});

function listItem(overrides: Partial<ProductListItem>): ProductListItem {
    return {
        id: 1,
        name: 'Nevera',
        sku: 'NEV-1',
        status: 'published',
        stock: 0,
        reservedUnits: 0,
        shortfallUnits: 0,
        price: { cents: centsUsd(25_000), usd: '$250,00', ves: null },
        categoryName: null,
        catalogsCount: 1,
        scheduledFor: null,
        updatedAt: null,
        updatedBy: null,
        ...overrides,
    };
}

it('pinta en el listado las disponibles, las apartadas y el faltante', () => {
    render(<StockCell product={listItem({ stock: 0, reservedUnits: 3, shortfallUnits: 2 })} />);

    expect(screen.getByText('0')).toBeInTheDocument();
    expect(screen.getByText('3 apartadas')).toBeInTheDocument();
    expect(screen.getByText('Faltan 2 apartadas')).toBeInTheDocument();
});

it('no pinta apartadas ni faltante en un producto sin apartados', () => {
    render(<StockCell product={listItem({ stock: 4 })} />);

    expect(screen.getByText('4')).toBeInTheDocument();
    expect(screen.queryByText(/apartadas/)).toBeNull();
});

it('en el listado, un producto sin inicial dice que no la lleva en lugar de una inicial de cero', () => {
    const columns = inventoryColumns({ draft: 'Borrador', published: 'Publicado', archived: 'Archivado' });
    const plan = { downPayment: { cents: centsUsd(0), usd: '$0,00', ves: null }, installments: 4, frequencyDays: 15 };

    const product = columns.find((column) => column.id === 'product');

    render(<>{product?.cell(listItem({ plan }))}</>);

    expect(screen.getByText('NEV-1 · Sin inicial · 4 cuotas')).toBeInTheDocument();
    expect(screen.queryByText(/Inicial $0,00/)).toBeNull();
});
