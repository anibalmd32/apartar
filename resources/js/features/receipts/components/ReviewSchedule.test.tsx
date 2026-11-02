import { ReviewSchedule } from '@/features/receipts/components/ReviewSchedule';
import { centsUsd } from '@/lib/money';
import { reviewProps } from '@/test/receipt-review-fixtures';
import { render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: {} }) }));

const money = (usd: string, cents: number) => ({ cents: centsUsd(cents), usd, ves: null });

it('en el celular el cronograma es una lista compacta con el monto, el estado y lo abonado solo cuando hay algo', () => {
    const { layaway } = reviewProps();

    render(
        <ReviewSchedule
            layaway={layaway}
            installments={[
                {
                    number: 1,
                    dueDateLabel: '25 sep 2026',
                    amount: money('$30,00', 3_000),
                    paid: money('$0,00', 0),
                    status: 'pending',
                    statusLabel: 'Pendiente',
                },
                {
                    number: 2,
                    dueDateLabel: '25 oct 2026',
                    amount: money('$30,00', 3_000),
                    paid: money('$12,00', 1_200),
                    status: 'partially_paid',
                    statusLabel: 'Abonada',
                },
            ]}
        />,
    );

    const list = screen.getByRole('list', { name: 'Cuotas' });
    const [first, second] = within(list).getAllByRole('listitem');

    expect(list).toHaveClass('md:hidden');
    expect(first).toHaveTextContent('Cuota 1');
    expect(first).toHaveTextContent('$30,00');
    expect(first).toHaveTextContent('Pendiente');
    expect(first).toHaveTextContent('Vence el 25 sep 2026');
    expect(first).not.toHaveTextContent('$0,00');
    expect(second).toHaveTextContent('Abonado $12,00');
    expect(screen.getByRole('table', { name: 'Cronograma' }).parentElement).toHaveClass('hidden', 'md:block');
});
