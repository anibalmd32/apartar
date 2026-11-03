import CatalogSummary from '@/pages/backoffice/catalogs/Summary';
import { money } from '@/test/t065-fixtures';
import { type CatalogSummaryProps } from '@/types/catalog-summary';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { currency: { display: 'usd_primary', rate: { rate: null } }, errors: {} } }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const PROPS: CatalogSummaryProps = {
    catalog: { id: 1, name: 'Temporada', editUrl: '/backoffice/catalogos/1/editar' },
    totals: {
        layaways: 3,
        units: 2,
        committed: money('$400,00', 40_000),
        paid: money('$280,00', 28_000),
        remaining: money('$120,00', 12_000),
        progress: 70,
        overdue: money('$20,00', 2_000),
        overdueLayaways: 1,
        upcoming: money('$30,00', 3_000),
        delivered: 1,
        awaitingDelivery: 1,
    },
    statuses: { active: 1, completed: 1, cancelled: 1 },
    products: [
        {
            id: 5,
            name: 'Mini Ups',
            sku: 'MIN-UPS-001',
            url: '/backoffice/productos/5/editar',
            price: money('$70,00', 7_000),
            stock: 20,
            reserved: 24,
            sold: 6,
            committed: money('$2.100,00', 210_000),
            paid: money('$1.076,54', 107_654),
            remaining: money('$1.023,46', 102_346),
            progress: 51,
        },
    ],
    collected: [
        { month: '2026-10', amount: money('$500,00', 50_000) },
        { month: '2026-11', amount: money('$80,00', 8_000) },
    ],
    debtors: [{ name: 'Julio Solano', url: '/backoffice/clientes/3', layaways: 2, remaining: money('$90,00', 9_000) }],
};

it('muestra lo vendido, lo cobrado, cada producto con su inventario y quiénes deben', () => {
    render(<CatalogSummary {...PROPS} />);

    expect(screen.getAllByRole('progressbar', { name: 'Pagado 70%' })).toHaveLength(1);
    expect(screen.getByText('3 apartados · 2 unidades')).toBeInTheDocument();
    expect(screen.getByText('1 apartado en mora')).toBeInTheDocument();

    const products = screen.getByRole('list', { name: 'Productos del catálogo' });
    expect(within(products).getByRole('link', { name: 'Mini Ups' })).toHaveAttribute('href', '/backoffice/productos/5/editar');
    expect(within(products).getByText('24')).toBeInTheDocument();
    expect(within(products).getByRole('progressbar', { name: 'Pagado 51%' })).toBeInTheDocument();

    expect(within(screen.getByRole('list', { name: 'Clientes que más deben' })).getByRole('link', { name: 'Julio Solano' })).toBeInTheDocument();
    expect(within(screen.getByRole('list', { name: 'Apartados por estado' })).getByText('Pagados')).toBeInTheDocument();
});
