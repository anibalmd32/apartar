import { PlanBreakdown } from '@/features/catalog/components/PlanBreakdown';
import { centsUsd } from '@/lib/money';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { currency: { display: 'usd_primary', rate: { rate: null } } } }),
}));

const money = (cents: number, usd: string) => ({ cents: centsUsd(cents), usd, ves: null });

it('al contado explica que se aparta pagando el total y que no hay cuotas', () => {
    render(
        <PlanBreakdown
            product={{
                isCashSale: true,
                downPayment: money(4_500, '$45,00'),
                installmentAmount: null,
                lastInstallmentAmount: null,
                installmentsCount: 4,
                frequencyDays: 15,
            }}
        />,
    );

    expect(screen.getByRole('heading')).toHaveTextContent('Al contado: apártalo hoy pagando $45,00 de una vez');
    expect(screen.getByText('Este producto no se vende en cuotas.')).toBeInTheDocument();
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
});

it('sin plan y sin ser al contado no muestra nada', () => {
    const { container } = render(
        <PlanBreakdown
            product={{
                downPayment: money(4_500, '$45,00'),
                installmentAmount: null,
                lastInstallmentAmount: null,
                installmentsCount: 4,
                frequencyDays: 15,
            }}
        />,
    );

    expect(container).toBeEmptyDOMElement();
});
