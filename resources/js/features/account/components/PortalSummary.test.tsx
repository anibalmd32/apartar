import { PortalSummary } from '@/features/account/components/PortalSummary';
import { centsUsd } from '@/lib/money';
import { type PortalTotals } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, Link: (await import('@/test/fakes')).FakeLink }));

function money(usd: string, cents: number) {
    return { cents: centsUsd(cents), usd, ves: null };
}

function totals(overrides: Partial<PortalTotals> = {}): PortalTotals {
    return {
        activeLayaways: 1,
        committed: money('$120,00', 12_000),
        paid: money('$30,00', 3_000),
        remaining: money('$90,00', 9_000),
        overdueInstallments: 0,
        catchUp: money('$0,00', 0),
        nextDueDate: '2026-11-04',
        nextDueDateLabel: '4 nov 2026',
        ...overrides,
    };
}

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } } } });
});

const TODAY = '2026-11-02';

function renderSummary(overrides: Partial<PortalTotals> = {}) {
    return render(<PortalSummary totals={totals(overrides)} label="Resumen de tus apartados" payUrl="/mi-cuenta/pagar" today={TODAY} />);
}

it('al día dice cuánto debe, cuándo vence y lleva a pagar', () => {
    renderSummary({ nextAmount: money('$30,00', 3_000) });

    expect(screen.getByText(/^Debes/)).toHaveTextContent('Debes $30,00');
    expect(screen.getByText('Vence el 4 nov 2026')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Pagar' })).toHaveAttribute('href', '/mi-cuenta/pagar');
    expect(screen.queryByText('Próximo pago')).not.toBeInTheDocument();
});

it('con cuotas vencidas dice el total para ponerse al día y desde cuándo venció', () => {
    renderSummary({ overdueInstallments: 2, catchUp: money('$45,00', 4_500), nextDueDate: '2026-10-21', nextDueDateLabel: '21 oct 2026' });

    expect(screen.getByText(/^Debes/)).toHaveTextContent('Debes $45,00');
    expect(screen.getByText('Venció el 21 oct 2026 · 2 cuotas vencidas')).toHaveClass('text-error');
});

it('nunca anuncia una fecha pasada como próxima', () => {
    renderSummary({ nextAmount: money('$30,00', 3_000), nextDueDate: '2026-10-30', nextDueDateLabel: '30 oct 2026' });

    expect(screen.getByText('Venció el 30 oct 2026')).toBeInTheDocument();
    expect(screen.queryByText(/Vence el/)).not.toBeInTheDocument();
});

it('si lo vencido ya está cubierto por comprobantes en revisión lo dice', () => {
    renderSummary({ overdueInstallments: 1, catchUp: money('$0,00', 0), nextDueDate: '2026-10-21', nextDueDateLabel: '21 oct 2026' });

    expect(screen.getByText('Tu pago está en revisión.')).toBeInTheDocument();
    expect(screen.queryByText(/^Debes/)).not.toBeInTheDocument();
    expect(screen.queryByText(/21 oct 2026/)).not.toBeInTheDocument();
});

it('si el próximo pago ya está cubierto por un comprobante en revisión no repite su fecha', () => {
    renderSummary({ nextDueDate: null, nextDueDateLabel: null, paymentInReview: true });

    expect(screen.getByText('Tu pago está en revisión.')).toBeInTheDocument();
});

it('en bolívares, lo pagado se muestra en dólares y no reconvertido a la tasa de hoy', () => {
    usePage.mockReturnValue({ props: { currency: { display: 'ves_primary', timezone: 'America/Caracas', rate: { rate: null } } } });
    renderSummary({
        paid: { cents: centsUsd(3_000), usd: '$30,00', ves: 'Bs. 1.500,00' },
        remaining: { cents: centsUsd(9_000), usd: '$90,00', ves: 'Bs. 4.500,00' },
    });

    expect(screen.getByText('Pagado').nextElementSibling).toHaveTextContent('$30,00');
    expect(screen.getByText('Pagado').nextElementSibling).not.toHaveTextContent('Bs.');
    expect(screen.getByText('Restante').nextElementSibling).toHaveTextContent('Bs. 4.500,00');
});
