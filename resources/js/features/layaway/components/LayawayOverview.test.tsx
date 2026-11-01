import { InstallmentPlan } from '@/features/layaway/components/LayawayOverview';
import { centsUsd } from '@/lib/money';
import { type BackofficeInstallmentRow } from '@/types/layaway-backoffice';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@/features/currency/components/MoneyLabel', () => ({ MoneyLabel: () => null }));
vi.mock('@/features/layaway/components/RescheduleInstallmentDialog', () => ({ RescheduleInstallmentDialog: () => null }));

const money = (cents: number) => ({ cents: centsUsd(cents), usd: `$${cents / 100}`, ves: null });

const row = (number: number, paid: number, statusLabel: string): BackofficeInstallmentRow => ({
    number,
    dueDate: '2999-01-01',
    dueDateLabel: '1 de enero',
    amount: money(3_000),
    paid: money(paid),
    statusLabel,
    reschedule: null,
});

it('el pago parcial lleva un color distinto al de la cuota pendiente', () => {
    render(<InstallmentPlan installments={[row(1, 1_000, 'Pago parcial'), row(2, 0, 'Pendiente')]} />);

    expect(screen.getByText('Pago parcial')).toHaveClass('badge-info');
    expect(screen.getByText('Pendiente')).toHaveClass('badge-neutral');
});
