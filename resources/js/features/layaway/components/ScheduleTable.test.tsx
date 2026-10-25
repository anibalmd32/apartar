import { ScheduleTable } from '@/features/layaway/components/ScheduleTable';
import { centsUsd } from '@/lib/money';
import { type InstallmentRow } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage }));

function money(usd: string, cents = 100) {
    return { cents: centsUsd(cents), usd, ves: null };
}

function row(number: number, overrides: Partial<InstallmentRow>): InstallmentRow {
    return {
        number,
        dueDate: '2026-09-25',
        dueDateLabel: '25 de septiembre',
        amount: money('$30,00', 3000),
        paid: money('$0,00', 0),
        pending: money('$30,00', 3000),
        status: 'pending',
        daysUntilDue: 10,
        tone: 'pending',
        ...overrides,
    };
}

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } } } });
});

it('dice en texto si la cuota está pagada, vencida o por vencer', () => {
    render(
        <ScheduleTable
            installments={[
                row(1, { tone: 'paid', status: 'paid', daysUntilDue: -20 }),
                row(2, { tone: 'overdue', daysUntilDue: -3 }),
                row(3, { tone: 'soon', daysUntilDue: 2 }),
                row(4, { tone: 'soon', daysUntilDue: 0 }),
                row(5, { tone: 'pending', daysUntilDue: 1 }),
            ]}
        />,
    );

    expect(screen.getByText('Pagada')).toHaveClass('badge-success');
    expect(screen.getByText('Venció hace 3 días')).toHaveClass('badge-error');
    expect(screen.getByText('Vence en 2 días')).toHaveClass('badge-warning');
    expect(screen.getByText('Vence hoy')).toBeInTheDocument();
    expect(screen.getByText('Vence mañana')).toBeInTheDocument();
});

it('enseña lo pagado y lo que falta de una cuota a medias', () => {
    render(<ScheduleTable installments={[row(1, { status: 'partially_paid', paid: money('$10,00', 1000), pending: money('$20,00', 2000) })]} />);

    expect(screen.getByText(/pagado/)).toHaveTextContent('pagado $10,00, falta $20,00');
});
