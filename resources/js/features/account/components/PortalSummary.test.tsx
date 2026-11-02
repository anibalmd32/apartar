import { PortalSummary } from '@/features/account/components/PortalSummary';
import { centsUsd } from '@/lib/money';
import { type PortalTotals } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage }));

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

it('al día muestra la fecha del próximo pago', () => {
    render(<PortalSummary totals={totals()} label="Resumen de tus apartados" />);

    expect(screen.getByText('Próximo pago').nextElementSibling).toHaveTextContent('4 nov 2026');
});

it('con cuotas vencidas muestra lo que falta para ponerse al día y no una fecha pasada', () => {
    render(
        <PortalSummary
            totals={totals({ overdueInstallments: 2, catchUp: money('$45,00', 4_500), nextDueDate: '2026-10-21', nextDueDateLabel: '21 oct 2026' })}
            label="Resumen de tus apartados"
        />,
    );

    expect(screen.getByText('Para ponerte al día').nextElementSibling).toHaveTextContent('$45,00');
    expect(screen.queryByText('21 oct 2026')).not.toBeInTheDocument();
});

it('si lo vencido ya está cubierto por comprobantes en revisión lo dice', () => {
    render(
        <PortalSummary
            totals={totals({ overdueInstallments: 1, catchUp: money('$0,00', 0), nextDueDate: '2026-10-21', nextDueDateLabel: '21 oct 2026' })}
            label="Resumen de tus apartados"
        />,
    );

    expect(screen.getByText('Próximo pago').nextElementSibling).toHaveTextContent('En revisión');
});

it('si el próximo pago ya está cubierto por un comprobante en revisión no repite su fecha', () => {
    render(<PortalSummary totals={totals({ nextDueDate: null, nextDueDateLabel: null, paymentInReview: true })} label="Resumen de tus apartados" />);

    expect(screen.getByText('Próximo pago').nextElementSibling).toHaveTextContent('En revisión');
});
