import Dashboard from '@/pages/backoffice/Dashboard';
import { dashboardProps } from '@/test/t065-fixtures';
import { type DashboardCollectToday, type DashboardPendingReceipt } from '@/types/dashboard';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/backoffice',
        props: {
            errors: {},
            currency: { display: 'usd_primary', rate: { rate: null } },
            teamMember: { name: 'Ana Pérez', capabilities: ['products'], organization: { name: 'Ferretería Oriente', slug: 'oriente' } },
        },
    }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children, actions, description }: { children: ReactNode; actions?: ReactNode; description?: ReactNode }) => (
        <main>
            <header>
                {description}
                {actions}
            </header>
            {children}
        </main>
    ),
}));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => (name === 'portal.access' ? 'https://apartar.app/mi-cuenta/acceso' : `/${name}`));
});

const receipt: DashboardPendingReceipt = {
    id: 7,
    reviewUrl: '/backoffice/comprobantes/7',
    customerName: 'José Rivas',
    layawayCode: 'AP-1',
    methodLabel: 'Pago móvil',
    declared: '$20,00',
    submittedAt: new Date(Date.now() - 3 * 3_600_000).toISOString(),
    overdue: false,
    file: { url: '/f', kind: 'image' },
};

const collect: DashboardCollectToday = {
    totalCents: 2_700,
    outstandingCents: 128_450,
    customers: 2,
    late: 1,
    rows: [
        { customerId: 1, name: 'Carla Méndez', phone: '0414-1234567', url: '/backoffice/clientes/1', dueCents: 1_500, daysLate: 3, installments: 1 },
        { customerId: 2, name: 'María Gómez', phone: null, url: '/backoffice/clientes/2', dueCents: 1_200, daysLate: 0, installments: 1 },
    ],
};

function column(name: string): HTMLElement {
    return screen.getByRole('region', { name });
}

it('el inicio es una pizarra de tres columnas: por revisar, por cobrar hoy y este mes', () => {
    render(<Dashboard {...dashboardProps()} pendingReceipts={[receipt]} collectToday={collect} />);

    expect(within(column('Por revisar')).getByText('6')).toBeInTheDocument();
    expect(within(column('Por revisar')).getByText(/2 con más de 48 h/)).toBeInTheDocument();
    expect(within(column('Por revisar')).getByRole('link', { name: 'Revisar' })).toHaveAttribute('href', '/backoffice/comprobantes/7');
    expect(within(column('Por cobrar hoy')).getByText('$27,00')).toBeInTheDocument();
    expect(within(column('Este mes')).getByText('$1.250,00')).toBeInTheDocument();
    expect(within(column('Este mes')).getByText(/falta \$1\.284,50/)).toBeInTheDocument();
});

it('cada cliente atrasado dice cuántos días y tiene su recordatorio por WhatsApp ya escrito', () => {
    render(<Dashboard {...dashboardProps()} pendingReceipts={[]} collectToday={collect} />);

    const collectColumn = column('Por cobrar hoy');

    expect(within(collectColumn).getByText('Atrasada 3 días')).toHaveClass('text-error');
    expect(within(collectColumn).getByText('Vence hoy')).toBeInTheDocument();

    const whatsapp = within(collectColumn).getByRole('link', { name: 'WhatsApp' });

    expect(whatsapp.getAttribute('href')).toContain('https://wa.me/584141234567?text=');
    expect(decodeURIComponent(whatsapp.getAttribute('href') ?? '')).toContain('Te escribo de Ferretería Oriente');
    expect(within(collectColumn).getByRole('link', { name: 'Ver' })).toHaveAttribute('href', '/backoffice/clientes/2');
});

it('sin pendientes, cada columna lo dice en vez de quedar vacía', () => {
    render(
        <Dashboard {...dashboardProps()} pendingReceipts={[]} collectToday={{ totalCents: 0, outstandingCents: 0, customers: 0, late: 0, rows: [] }} />,
    );

    expect(within(column('Por revisar')).getByText('Nada por revisar.')).toBeInTheDocument();
    expect(within(column('Por cobrar hoy')).getByText('Todo al día.')).toBeInTheDocument();
});

it('quien no ve la cobranza no recibe la columna de por cobrar', () => {
    render(<Dashboard {...dashboardProps()} pendingReceipts={[]} collectToday={null} />);

    expect(screen.queryByRole('region', { name: 'Por cobrar hoy' })).not.toBeInTheDocument();
    expect(column('Este mes')).toBeInTheDocument();
});

it('una métrica caída es un guion', () => {
    render(<Dashboard {...dashboardProps({ pendingReceipts: null, longOverdueLayaways: null })} pendingReceipts={[]} collectToday={collect} />);

    expect(within(column('Por revisar')).getByText('—')).toBeInTheDocument();
    expect(within(screen.getByRole('link', { name: 'Atraso largo' }).closest('li')!).getByText('—')).toBeInTheDocument();
});

it('ya no ofrece organizar tarjetas ni cambiar a dos columnas', () => {
    render(<Dashboard {...dashboardProps()} pendingReceipts={[]} collectToday={collect} />);

    expect(screen.queryByRole('button', { name: /Organizar|Dos columnas/ })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Nuevo producto/ })).toHaveAttribute('href', '/backoffice/productos/crear');
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
    expect(screen.getByText(/factura vencida desde el 25 sept 2026/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Págala' })).toHaveAttribute('href', '/backoffice/suscripcion/facturas');
    expect(screen.getByRole('link', { name: 'Añade una' })).toHaveAttribute('href', '/backoffice/cuentas-de-cobro');
});

it('escribe a mano el día y la tasa de hoy', () => {
    render(
        <Dashboard
            {...dashboardProps({ rate: { rate: 893.2227, source: 'bcv', sourceLabel: 'BCV', publishedAt: new Date().toISOString(), isStale: false } })}
        />,
    );

    expect(screen.getByText(/^Hoy, /)).toHaveClass('font-hand');
    expect(screen.getByText('Bs. 893,22')).toBeInTheDocument();
});

it('una instalación nueva ve el checklist de arranque', () => {
    render(<Dashboard {...dashboardProps({ setup: { emailVerified: true, paymentAccount: true, product: false, publishedCatalog: false } })} />);

    expect(screen.getByRole('region', { name: 'Para empezar' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Carga tu primer producto' })).toHaveAttribute('href', '/backoffice/productos/crear');
    expect(screen.queryByRole('link', { name: 'Añade una cuenta de cobro' })).not.toBeInTheDocument();
});
