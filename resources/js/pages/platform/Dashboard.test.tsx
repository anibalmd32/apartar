import { centsUsd } from '@/lib/money';
import PlatformDashboard from '@/pages/platform/Dashboard';
import { type PlatformMetrics } from '@/types/platform';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@/layouts/platform-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const money = (usd: string) => ({ cents: centsUsd(0), usd, ves: null });

const metrics: PlatformMetrics = {
    organizations: { total: 47, active: 39, suspended: 3, newLast30Days: 5 },
    byPlan: { free: 39, pro: 8 },
    mrr: money('$142,00'),
    invoices: { pendingReview: 3, overdue: 2 },
    network: { accounts: 1230, subscriptions: 2890, multiBusinessAccounts: 214 },
    gmv: null,
};

it('pinta cada bloque y la definición del MRR', () => {
    render(<PlatformDashboard metrics={metrics} />);

    expect(within(screen.getByRole('region', { name: 'MRR' })).getByText('$142,00')).toBeInTheDocument();
    expect(screen.getByText(/en gracia y vencidas; sin suspendidas ni canceladas/)).toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Red' })).getByText('214')).toBeInTheDocument();
});

it('un bloque que falló dice «—» y el resto carga', () => {
    render(<PlatformDashboard metrics={metrics} />);

    expect(within(screen.getByRole('region', { name: 'Volumen en 30 días' })).getByText('—')).toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Organizaciones' })).getByText('47')).toBeInTheDocument();
});

it('dice cuánto cambió frente al mes pasado y los detalles de cada tarjeta', () => {
    render(
        <PlatformDashboard
            metrics={{
                ...metrics,
                invoices: { pendingReview: 3, overdue: 2, pendingReviewOld: 1 },
                suspendedLongOverdue: 2,
                monthly: [
                    { month: '2026-09', cents: 100_000 },
                    { month: '2026-10', cents: 108_400 },
                ],
                periodDays: 90,
            }}
            periods={[30, 90, 365]}
        />,
    );

    expect(screen.getByText('+8,4% vs. mes pasado')).toBeInTheDocument();
    expect(screen.getByText('2 con factura vencida > 7 d')).toBeInTheDocument();
    expect(screen.getByText('1 con más de 48 h')).toBeInTheDocument();
    expect(screen.getByText('Oct: $1.084')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Volumen en 90 días' })).toBeInTheDocument();
});

it('resume el correo de los últimos 30 días y enlaza a sus métricas', () => {
    render(<PlatformDashboard metrics={metrics} mail={{ sent: 1200, deliveryRate: 0.98, openRate: 0.4, bounceRate: null, unreadThreads: 3 }} />);

    const card = screen.getByRole('region', { name: 'Correo' });

    expect(within(card).getByText('1.200')).toBeInTheDocument();
    expect(within(card).getByText(/98\s?%/)).toBeInTheDocument();
    expect(within(card).getByText('—')).toBeInTheDocument();
    expect(within(card).getByRole('link', { name: 'Ver métricas' }).getAttribute('href')).toBe('/plataforma/correos');
});
