import CollectionIndex from '@/pages/backoffice/collections/Index';
import { CUSTOMER, collectionProps, installmentRow, money } from '@/test/t065-fixtures';
import { type CollectionCustomerRow } from '@/types/collections';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { routerPost, routerPut, usePage } = vi.hoisted(() => ({ routerPost: vi.fn(), routerPut: vi.fn(), usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage,
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), post: routerPost, put: routerPut, reload: vi.fn(), on: () => () => undefined },
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const GROUP: CollectionCustomerRow = {
    id: 9,
    customer: { ...CUSTOMER, url: '/backoffice/clientes/9' },
    layaway: { code: '', url: '/backoffice/clientes/9' },
    installments: [
        installmentRow({ id: 1, productName: 'Bolso tote', delivery: { deliveredAt: null, url: '/backoffice/apartados/AP-7K3M9QRT/entregar' } }),
        installmentRow({
            id: 2,
            layaway: { code: 'AP-Q2W4E6R8', url: '/backoffice/apartados/AP-Q2W4E6R8' },
            productName: 'Sandalias',
            delivery: { deliveredAt: '2026-09-01', url: null },
        }),
    ],
    count: 2,
    layaways: 2,
    pending: money('$40,00', 4_000),
    daysLate: 5,
};

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', rate: { rate: null } }, errors: {}, flash: {} } });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    routerPost.mockReset();
    routerPut.mockReset();
});

afterEach(() => {
    vi.restoreAllMocks();
});

function renderGrouped() {
    render(<CollectionIndex {...collectionProps([])} kind="customers" rows={[GROUP]} grouped groupingUrl="/backoffice/cobranza/agrupar" />);
}

it('cada cliente se despliega para ver sus cuotas con producto y entrega', () => {
    renderGrouped();
    const table = screen.getByRole('table');

    expect(within(table).getByText('2 cuotas en 2 apartados')).toBeInTheDocument();
    expect(within(table).queryByText('Bolso tote')).toBeNull();

    fireEvent.click(within(table).getByRole('button', { name: `Ver las cuotas de ${CUSTOMER.name}` }));

    const installments = within(table).getByRole('list', { name: 'Cuotas del cliente' });
    expect(within(installments).getByText('Bolso tote')).toBeInTheDocument();
    expect(within(installments).getByText('Entregado el 1 sept 2026')).toBeInTheDocument();

    fireEvent.click(within(installments).getByRole('button', { name: 'Marcar entregado' }));
    expect(routerPost).toHaveBeenCalledWith(
        '/backoffice/apartados/AP-7K3M9QRT/entregar',
        { volver: true },
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('el interruptor guarda la preferencia de agrupar', () => {
    renderGrouped();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Agrupar por cliente' }));

    expect(routerPut).toHaveBeenCalledWith('/backoffice/cobranza/agrupar', { grouped: false }, expect.anything());
});
