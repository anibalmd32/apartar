import Dashboard from '@/pages/backoffice/Dashboard';
import { planState, usage } from '@/test/plan-fixtures';
import { dashboardProps } from '@/test/t065-fixtures';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/backoffice',
        props: {
            errors: {},
            currency: { display: 'usd_primary', rate: { rate: null } },
            teamMember: { name: 'Ana Pérez', organization: { name: 'Ferretería Oriente', slug: 'oriente' } },
        },
    }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

it('cada tarjeta lleva a su lista filtrada', () => {
    render(<Dashboard {...dashboardProps()} />);

    expect(screen.getByRole('link', { name: /Comprobantes por revisar\s*6\s*2\s*con más de 48 h/ })).toHaveAttribute(
        'href',
        '/backoffice/comprobantes?status=pending_review',
    );
    expect(screen.getByRole('link', { name: /Vencen en 7 días\s*4/ })).toHaveAttribute('href', '/backoffice/cobranza?preset=due_within_7_days');
    expect(screen.getByRole('link', { name: /Clientes en mora\s*3/ })).toHaveAttribute('href', '/backoffice/clientes?overdue=1');
    expect(screen.getByText('$1.250,00')).toBeInTheDocument();
});

it('una métrica caída es un guion y sin avisos no hay sección', () => {
    render(<Dashboard {...dashboardProps({ customersOverdue: null })} />);

    expect(screen.getByRole('link', { name: /Clientes en mora\s*—/ })).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Avisos' })).not.toBeInTheDocument();
});

it('pinta los avisos con su acción', () => {
    render(
        <Dashboard
            {...dashboardProps({
                warnings: [{ kind: 'invoice_overdue', dueDate: '2026-09-25' }, { kind: 'no_active_payment_account' }],
            })}
        />,
    );

    expect(screen.getByRole('region', { name: 'Avisos' })).toBeInTheDocument();
    expect(screen.getByText(/factura vencida desde el 25\/09\/2026/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Págala' })).toHaveAttribute('href', '/backoffice/suscripcion/facturas');
    expect(screen.getByRole('link', { name: 'Añade una' })).toHaveAttribute('href', '/backoffice/cuentas-de-cobro');
});

it('destaca el consumo del plan al 80 % y dice la edad de la tasa', () => {
    render(
        <Dashboard
            {...dashboardProps({
                plan: planState({
                    usage: {
                        users: usage(1, 2),
                        catalogs: usage(1, 1),
                        products: usage(24, 30),
                        active_layaways: usage(11, 15),
                        storage_mb: usage(10, 500),
                        payment_accounts: usage(1, 3),
                    },
                }),
                rate: {
                    rate: 42.5,
                    source: 'bcv',
                    sourceLabel: 'BCV',
                    publishedAt: new Date(Date.now() - 5 * 3_600_000 - 60_000).toISOString(),
                    isStale: false,
                },
            })}
        />,
    );

    const item = (text: string) => screen.getAllByRole('listitem').find((li) => li.textContent?.startsWith(text));

    expect(item('productos')).toHaveAttribute('data-near', 'true');
    expect(item('productos')).toHaveTextContent('cerca del límite');
    expect(item('apartados activos')).not.toHaveAttribute('data-near');
    expect(screen.getByText(/actualizada hace 5 h/)).toBeInTheDocument();
});

it('una instalación nueva ve el checklist de arranque', () => {
    render(<Dashboard {...dashboardProps({ setup: { emailVerified: true, paymentAccount: true, product: false, publishedCatalog: false } })} />);

    expect(screen.getByRole('region', { name: 'Para empezar' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Carga tu primer producto' })).toHaveAttribute('href', '/backoffice/productos/crear');
    expect(screen.queryByRole('link', { name: 'Añade una cuenta de cobro' })).not.toBeInTheDocument();
});
