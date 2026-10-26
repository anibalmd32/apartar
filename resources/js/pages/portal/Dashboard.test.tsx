import { centsUsd } from '@/lib/money';
import Dashboard, { VISIBLE_BUSINESSES } from '@/pages/portal/Dashboard';
import { fakeRoute } from '@/test/fakes';
import { type RateView } from '@/types/exchange';
import { type BusinessBlock, type LayawaySummary, type PortalDashboardProps } from '@/types/portal';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

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
        total: money('$200,00'),
        paid: money('$50,00'),
        remaining: money('$150,00'),
        nextDueDate: isTerminal ? null : '2026-09-25',
        nextDueDateLabel: isTerminal ? null : '25 de septiembre',
        overdueInstallments: 0,
    };
}

function block(name: string, slug: string, layaways: LayawaySummary[], history: LayawaySummary[] = []): BusinessBlock {
    return {
        business: { name, slug, logoUrl: null, brandColor: null, brandingEnabled: false },
        closed: false,
        rate: { rate: slug === 'boutique' ? 50 : 40, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false },
        totals: {
            activeLayaways: layaways.length,
            committed: money('$200,00'),
            paid: money('$50,00'),
            remaining: money('$150,00'),
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
    connectGoogleUrl: '/cuenta/google/redirect?luego=%2Fmi-cuenta',
    subscriptionsUrl: null,
    claimUrl: null,
    feedPreview: null,
    push: { publicKey: null, subscribeUrl: '/mi-cuenta/push', hasEmail: false },
};

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: NO_RATE } } });
});

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
    expect(within(ferreteria).getByText(/Tasa BCV Bs. 40,00/)).toBeInTheDocument();
    expect(within(boutique).getByText(/Tasa BCV Bs. 50,00/)).toBeInTheDocument();
});

it('separa el historial y enlaza cada apartado con su detalle por código', () => {
    render(<Dashboard {...PROPS} />);

    expect(screen.getByText('Historial (1)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Nevera Mabe/ })).toHaveAttribute('href', '/mi-cuenta/apartados/AP-7K3M9QRT');
    expect(screen.getByText('Próximo vencimiento: 25 de septiembre')).toBeInTheDocument();
});

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
                    amount: money('$30,00'),
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

it('en la sesión acotada propone conectar Google', () => {
    render(<Dashboard {...PROPS} />);

    expect(screen.getByRole('link', { name: 'Conectar Google' })).toHaveAttribute('href', '/cuenta/google/redirect?luego=%2Fmi-cuenta');
});

it('con la cuenta y sin fichas, explica el vacío y enlaza lo que sigue', () => {
    render(
        <Dashboard
            {...PROPS}
            session={{ mode: 'account', displayName: 'María Pérez' }}
            businesses={[]}
            connectGoogleUrl={null}
            subscriptionsUrl="/mi-cuenta/suscripciones"
            claimUrl="/mi-cuenta/reclamar"
        />,
    );

    expect(screen.getByText('Aquí verás tus apartados de todos los negocios.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver los catálogos que sigues' })).toHaveAttribute('href', '/mi-cuenta/suscripciones');
    expect(screen.getByRole('link', { name: 'Recuperar mi historial' })).toHaveAttribute('href', '/mi-cuenta/reclamar');
    expect(screen.queryByRole('link', { name: 'Conectar Google' })).toBeNull();
});

it('con más de cinco negocios enseña cinco y ofrece ver todos', () => {
    const many = ['a', 'b', 'c', 'd', 'e', 'f', 'g'].map((slug) =>
        block(`Negocio ${slug}`, slug, [layaway(`AP-${slug.toUpperCase()}`, 'Algo', 'Al día')]),
    );

    render(<Dashboard {...PROPS} session={{ mode: 'account', displayName: 'María' }} businesses={many} connectGoogleUrl={null} />);

    expect(screen.getAllByRole('region', { name: /Tus apartados en/ })).toHaveLength(VISIBLE_BUSINESSES);
    fireEvent.click(screen.getByRole('button', { name: 'Ver todos mis negocios (7)' }));
    expect(screen.getAllByRole('region', { name: /Tus apartados en/ })).toHaveLength(7);
});
