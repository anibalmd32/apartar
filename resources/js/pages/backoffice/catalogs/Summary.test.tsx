import CatalogSummary from '@/pages/backoffice/catalogs/Summary';
import { money } from '@/test/t065-fixtures';
import { type CatalogSummaryProps } from '@/types/catalog-summary';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { get },
    usePage: () => ({ props: { currency: { display: 'usd_primary', rate: { rate: null } }, errors: {} } }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const PROPS: CatalogSummaryProps = {
    catalog: { id: 1, name: 'Temporada', editUrl: '/backoffice/catalogos/1/editar', url: '/backoffice/catalogos/1/resumen' },
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
    debtors: [{ name: 'Julio Solano', url: '/backoffice/clientes/3', layaways: 2, remaining: money('$90,00', 9_000) }],
    period: {
        from: '2026-06-01',
        to: '2026-11-03',
        preset: '6m',
        granularity: 'month',
        presets: [
            { key: 'mes', label: 'Este mes', from: '2026-11-01', to: '2026-11-03' },
            { key: '6m', label: 'Últimos 6 meses', from: '2026-06-01', to: '2026-11-03' },
        ],
    },
    periodTotals: { collected: money('$580,00', 58_000), payments: 3, average: money('$193,33', 19_333), layaways: 2 },
    series: [
        { key: '2026-10', label: 'oct 26', collected: money('$500,00', 50_000), payments: 2, layaways: 1, parallel: null },
        { key: '2026-11', label: 'nov 26', collected: money('$80,00', 8_000), payments: 1, layaways: 1, parallel: null },
    ],
    safeguard: null,
    payments: [
        {
            id: 9,
            date: '2 nov 2026',
            customer: 'Julio Solano',
            product: 'Mini Ups',
            layawayCode: 'AP-7K2',
            layawayUrl: '/backoffice/apartados/AP-7K2',
            amount: money('$80,00', 8_000),
            declared: 'Bs. 71.200,00',
            parallel: null,
            safeguard: null,
        },
    ],
    paymentsTruncated: false,
};

const SAFEGUARD: CatalogSummaryProps['safeguard'] = {
    parallel: money('$490,00', 49_000),
    gap: money('-$90,00', -9_000),
    payments: 3,
    recorded: 1,
    withoutRate: 1,
    byDestination: [
        { label: 'Binance (USDT)', amount: money('$400,00', 40_000), payments: 1, recorded: true },
        { label: 'Sin registrar', amount: money('$90,00', 9_000), payments: 1, recorded: false },
    ],
};

it('muestra lo vendido, lo cobrado, cada producto con su inventario y quiénes deben', () => {
    render(<CatalogSummary {...PROPS} />);

    expect(screen.getAllByRole('progressbar', { name: 'Pagado 70%' })).toHaveLength(1);
    expect(screen.getByText('3 apartados · 2 unidades')).toBeInTheDocument();
    expect(screen.getByText('1 apartado atrasado')).toBeInTheDocument();

    const products = screen.getByRole('list', { name: 'Productos del catálogo' });
    expect(within(products).getByRole('link', { name: 'Mini Ups' })).toHaveAttribute('href', '/backoffice/productos/5/editar');
    expect(within(products).getByText('24')).toBeInTheDocument();
    expect(within(products).getByRole('progressbar', { name: 'Pagado 51%' })).toBeInTheDocument();

    expect(within(screen.getByRole('list', { name: 'Clientes que más deben' })).getByRole('link', { name: 'Julio Solano' })).toBeInTheDocument();
    expect(within(screen.getByRole('list', { name: 'Apartados por estado' })).getByText('Pagados')).toBeInTheDocument();
});

it('el periodo resume lo cobrado, señala el mes más fuerte y cambia con un periodo rápido', () => {
    render(<CatalogSummary {...PROPS} />);

    expect(screen.getByText('El mes que más se cobró').closest('.stat-card, a, div')).toHaveTextContent('oct 26');
    expect(screen.getByRole('button', { name: 'Últimos 6 meses' })).toHaveAttribute('aria-pressed', 'true');
    expect(within(screen.getByRole('list', { name: 'Pagos del periodo' })).getByRole('link', { name: 'AP-7K2' })).toHaveAttribute(
        'href',
        '/backoffice/apartados/AP-7K2',
    );
    expect(screen.queryByRole('region', { name: 'Resguardo en dólar paralelo' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Este mes' }));

    expect(get).toHaveBeenCalledWith(
        '/backoffice/catalogos/1/resumen',
        { desde: '2026-11-01', hasta: '2026-11-03' },
        expect.objectContaining({ preserveState: true }),
    );
});

it('con el resguardo activo muestra cuánto vale en paralelo, dónde está y qué pagos faltan por registrar', () => {
    render(<CatalogSummary {...PROPS} safeguard={SAFEGUARD} payments={[{ ...PROPS.payments[0]!, parallel: '$67,43' }]} />);

    const safeguard = screen.getByRole('region', { name: 'Resguardo en dólar paralelo' });
    expect(
        within(safeguard).getByText(
            '1 de 3 pagos tienen registrado dónde quedó el dinero; los demás aparecen abajo como «Sin registrar». 1 pago es anterior al histórico del paralelo y no suma.',
        ),
    ).toBeInTheDocument();
    expect(within(screen.getByRole('list', { name: 'Dónde está resguardado' })).getByText(/Binance \(USDT\)/)).toBeInTheDocument();

    const payments = screen.getByRole('list', { name: 'Pagos del periodo' });
    expect(within(payments).getByText('$67,43 en paralelo')).toBeInTheDocument();
    expect(within(payments).getByText('Sin registrar')).toBeInTheDocument();
});
