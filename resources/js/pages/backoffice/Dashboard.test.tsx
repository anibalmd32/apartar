import Dashboard from '@/pages/backoffice/Dashboard';
import { planState, usage } from '@/test/plan-fixtures';
import { dashboardProps } from '@/test/t065-fixtures';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

/**
 * El panel del negocio (`REQ-BOF-044`, `T-065`).
 */

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

/** RB-1, RB-3 · cada tarjeta con su cifra y el enlace a la lista que la origina. */
it('cada tarjeta lleva a su lista filtrada', () => {
    render(<Dashboard {...dashboardProps()} />);

    expect(screen.getByRole('link', { name: /Comprobantes por revisar\s*6\s*2 atrasados/ })).toHaveAttribute(
        'href',
        '/backoffice/comprobantes?status=pending_review',
    );
    expect(screen.getByRole('link', { name: /Vencen en 7 días\s*4/ })).toHaveAttribute('href', '/backoffice/cobranza?preset=due_within_7_days');
    expect(screen.getByRole('link', { name: /Clientes en mora\s*3/ })).toHaveAttribute('href', '/backoffice/clientes?overdue=1');
    expect(screen.getByText('$1,250.00')).toBeInTheDocument();
});

/** SP-4, SP-6 · una métrica que falló es un guion; sin avisos no hay sección. */
it('una métrica caída es un guion y sin avisos no hay sección', () => {
    render(<Dashboard {...dashboardProps({ customersOverdue: null })} />);

    expect(screen.getByRole('link', { name: /Clientes en mora\s*—/ })).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Avisos' })).not.toBeInTheDocument();
});

/** RB-4 · cada aviso con su acción. */
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
    expect(screen.getByRole('link', { name: 'Añade una' })).toHaveAttribute('href', '/backoffice/cuentas-de-cobro');
});

/** RB-6 · el consumo al 80 % o más se destaca; RB-1 · la tasa con su antigüedad. */
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

    // El nombre de la métrica es el del servidor (`T-067`), en minúsculas y
    // capitalizado por CSS: se busca la fila entera.
    const item = (text: string) => screen.getAllByRole('listitem').find((li) => li.textContent?.includes(text));

    expect(item('productos: 24')).toHaveClass('text-warning');
    expect(item('apartados activos: 11')).not.toHaveClass('text-warning');
    expect(screen.getByText(/publicada hace 5 h/)).toBeInTheDocument();
});

/** SP-1 · una instalación nueva ve qué le falta para vender. */
it('una instalación nueva ve el checklist de arranque', () => {
    render(<Dashboard {...dashboardProps({ setup: { emailVerified: true, paymentAccount: true, product: false, publishedCatalog: false } })} />);

    expect(screen.getByRole('region', { name: 'Para empezar' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Carga tu primer producto' })).toHaveAttribute('href', '/backoffice/productos/crear');
    expect(screen.queryByRole('link', { name: 'Añade una cuenta de cobro' })).not.toBeInTheDocument();
});
