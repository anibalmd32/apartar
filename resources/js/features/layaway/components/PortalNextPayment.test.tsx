import { PortalNextPayment } from '@/features/layaway/components/PortalNextPayment';
import { centsUsd } from '@/lib/money';
import { type RateView } from '@/types/exchange';
import { type NextPaymentSuggestion } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, Link: (await import('@/test/fakes')).FakeLink }));

const NO_RATE: RateView = { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false };

const money = (cents: number, usd: string) => ({ cents: centsUsd(cents), usd, ves: null });

const OVERDUE: NextPaymentSuggestion = {
    kind: 'overdue',
    installmentNumber: null,
    overdueInstallments: 2,
    amount: money(4_500, '$45,00'),
    started: false,
    covered: false,
    alternative: { installmentNumber: 1, amount: money(2_250, '$22,50') },
    inReview: null,
    payOff: money(9_000, '$90,00'),
};

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: NO_RATE } } });
});

it('con cuotas vencidas pide ponerse al día con el total y ofrece la cuota más antigua', () => {
    render(<PortalNextPayment suggestion={OVERDUE} receiptUrl="/comprobante" />);

    expect(screen.getByRole('heading', { name: 'Ponte al día' })).toBeInTheDocument();
    expect(screen.getByText(/Tus 2 cuotas vencidas/)).toBeInTheDocument();
    expect(screen.getByText('$45,00')).toBeInTheDocument();
    expect(screen.getByText(/paga al menos la cuota 1/)).toHaveTextContent('$22,50');
    expect(screen.getByRole('link', { name: 'Subir comprobante' })).toHaveClass('btn-primary');
});

it('descuenta lo que está en revisión y dice cuándo se envió', () => {
    render(
        <PortalNextPayment
            suggestion={{
                ...OVERDUE,
                amount: money(2_000, '$20,00'),
                started: true,
                alternative: null,
                inReview: { amount: money(2_500, '$25,00'), count: 1, submittedOn: '1 nov' },
            }}
            receiptUrl="/comprobante"
        />,
    );

    expect(screen.getByText(/Lo que falta de tus 2 cuotas vencidas/)).toBeInTheDocument();
    expect(screen.getByText(/Ya enviaste/)).toHaveTextContent('Ya enviaste $25,00 el 1 nov; lo están revisando.');
    expect(screen.queryByText(/paga al menos/)).toBeNull();
});

it('si lo enviado cubre la sugerencia, deja de pedir el pago y el botón pasa a secundario', () => {
    render(
        <PortalNextPayment
            suggestion={{
                ...OVERDUE,
                kind: 'installment',
                installmentNumber: 1,
                overdueInstallments: 0,
                amount: money(0, '$0,00'),
                covered: true,
                alternative: null,
                inReview: { amount: money(4_125, '$41,25'), count: 1, submittedOn: '30 oct' },
            }}
            receiptUrl="/comprobante"
        />,
    );

    expect(screen.queryByText(/Te sugerimos pagar/)).toBeNull();
    expect(screen.getByRole('heading', { name: 'Tu pago está en revisión' })).toBeInTheDocument();
    expect(screen.getByText(/Ya enviaste/)).toHaveTextContent('Ya enviaste $41,25 el 30 oct; lo están revisando.');
    expect(screen.getByRole('link', { name: 'Subir otro comprobante' })).toHaveClass('btn-secondary');
    expect(screen.getByRole('link', { name: 'Subir otro comprobante' })).not.toHaveClass('btn-primary');
});
