import { centsUsd } from '@/lib/money';
import Dashboard from '@/pages/portal/Dashboard';
import { fakeRoute } from '@/test/fakes';
import { type RateView } from '@/types/exchange';
import { type BusinessBlock, type LayawaySummary, type PortalDashboardProps } from '@/types/portal';
import { render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * La portada del portal (`REQ-LAY-020`).
 */

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

const NO_RATE: RateView = { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false };

function money(usd: string) {
    return { cents: centsUsd(100), usd, ves: null };
}

function layaway(code: string, productName: string, statusLabel: string, isTerminal = false): LayawaySummary {
    return {
        code,
        status: isTerminal ? 'expired' : 'active',
        statusLabel,
        isTerminal,
        acceptsPayments: !isTerminal,
        productName,
        quantity: 1,
        total: money('$200.00'),
        paid: money('$50.00'),
        remaining: money('$150.00'),
        nextDueDate: isTerminal ? null : '2026-09-25',
        nextDueDateLabel: isTerminal ? null : '25 de septiembre',
        overdueInstallments: 0,
    };
}

function block(name: string, slug: string, layaways: LayawaySummary[], history: LayawaySummary[] = []): BusinessBlock {
    return {
        business: { name, slug, logoUrl: null, brandColor: null, brandingEnabled: false },
        closed: false,
        totals: {
            activeLayaways: layaways.length,
            committed: money('$200.00'),
            paid: money('$50.00'),
            remaining: money('$150.00'),
            overdueInstallments: 1,
            nextDueDate: '2026-09-25',
            nextDueDateLabel: '25 de septiembre',
        },
        layaways,
        history,
    };
}

const PROPS: PortalDashboardProps = {
    session: { mode: 'scoped', displayName: 'María González' },
    businesses: [
        block(
            'Ferretería Oriente',
            'ferreteria',
            [layaway('AP-7K3M9QRT', 'Nevera Mabe', 'Al día')],
            [layaway('AP-QX2H8MNB', 'Taladro', 'Vencido sin inicial', true)],
        ),
    ],
    totals: null,
    alerts: [],
};

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: NO_RATE } } });
});

/** RB-2 · cada negocio en su bloque, con su nombre; nunca mezclados. */
it('pinta un bloque por negocio con sus propios apartados', () => {
    render(
        <Dashboard
            {...PROPS}
            businesses={[
                block('Ferretería Oriente', 'ferreteria', [layaway('AP-7K3M9QRT', 'Nevera', 'Al día')]),
                block('Boutique Sur', 'boutique', [layaway('AP-ZZ2H8MNB', 'Vestido', 'Al día')]),
            ]}
        />,
    );

    const ferreteria = screen.getByRole('region', { name: 'Tus apartados en Ferretería Oriente' });
    const boutique = screen.getByRole('region', { name: 'Tus apartados en Boutique Sur' });

    expect(within(ferreteria).getByText('Nevera')).toBeInTheDocument();
    expect(within(ferreteria).queryByText('Vestido')).toBeNull();
    expect(within(boutique).getByText('Vestido')).toBeInTheDocument();
});

/** RB-5 · los terminales van al historial, y cada apartado lleva a su detalle. */
it('separa el historial y enlaza cada apartado con su detalle por código', () => {
    render(<Dashboard {...PROPS} />);

    expect(screen.getByText('Historial (1)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Nevera Mabe/ })).toHaveAttribute('href', '/mi-cuenta/apartados/AP-7K3M9QRT');
    expect(screen.getByText('Próximo vencimiento: 25 de septiembre')).toBeInTheDocument();
});

/** `REQ-LAY-020` RB-8 y `REQ-LAY-023` SP-5 · las alertas, antes que los bloques; sin ninguna, ni el contenedor. */
it('pinta las alertas encima de los bloques, y nada si no hay', () => {
    const { rerender } = render(<Dashboard {...PROPS} />);

    expect(screen.queryByRole('region', { name: 'Avisos' })).toBeNull();

    rerender(
        <Dashboard
            {...PROPS}
            alerts={[
                {
                    kind: 'overdue',
                    count: 1,
                    amount: money('$30.00'),
                    businessName: 'Ferretería Oriente',
                    actionUrl: '/mi-cuenta/apartados/AP-7K3M9QRT',
                },
            ]}
        />,
    );

    const alerts = screen.getByRole('region', { name: 'Avisos' });
    const block = screen.getByRole('region', { name: 'Tus apartados en Ferretería Oriente' });

    expect(alerts.compareDocumentPosition(block) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
});

it('sin apartados, lo dice', () => {
    render(<Dashboard {...PROPS} businesses={[]} />);

    expect(screen.getByText('No tienes apartados todavía.')).toBeInTheDocument();
});
