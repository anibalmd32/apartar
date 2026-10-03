import { centsUsd } from '@/lib/money';
import LayawayShow from '@/pages/portal/LayawayShow';
import { fakeRoute } from '@/test/fakes';
import { type PortalLayawayShowProps } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * El detalle del apartado en el portal (`REQ-LAY-021`).
 */

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

function money(usd: string, cents = 100) {
    return { cents: centsUsd(cents), usd, ves: null };
}

function props(overrides: Partial<PortalLayawayShowProps> = {}, layaway: Partial<PortalLayawayShowProps['layaway']> = {}): PortalLayawayShowProps {
    return {
        business: { name: 'Ferretería Oriente', slug: 'ferreteria', logoUrl: null, brandColor: null, brandingEnabled: false },
        layaway: {
            code: 'AP-7K3M9QRT',
            status: 'active',
            statusLabel: 'Al día',
            isTerminal: false,
            acceptsPayments: true,
            productName: 'Nevera Mabe',
            quantity: 1,
            total: money('$200.00'),
            paid: money('$50.00'),
            remaining: money('$150.00'),
            nextDueDate: '2026-09-25',
            nextDueDateLabel: '25 de septiembre',
            overdueInstallments: 0,
            downPayment: money('$50.00'),
            expiresAt: null,
            activatedAt: '10 de septiembre de 2026',
            statusMessage: null,
            ...layaway,
        },
        installments: [
            {
                number: 1,
                dueDate: '2026-09-25',
                dueDateLabel: '25 de septiembre',
                amount: money('$30.00', 3000),
                paid: money('$0.00', 0),
                pending: money('$30.00', 3000),
                status: 'pending',
                daysUntilDue: 2,
                tone: 'soon',
            },
        ],
        nextPaymentSuggestion: { kind: 'installment', installmentNumber: 1, amount: money('$30.00'), payOff: money('$150.00') },
        paymentAccounts: [],
        catalogUrl: null,
        receiptUrl: null,
        ...overrides,
    };
}

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    usePage.mockReturnValue({
        props: {
            auth: { portal: { fullName: 'María González' } },
            currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } },
        },
    });
});

/** RB-2, RB-4 y RB-9 · el negocio, el cronograma y el próximo pago. */
it('dice de qué negocio es, pinta el cronograma y sugiere la cuota más antigua', () => {
    render(<LayawayShow {...props()} />);

    expect(screen.getByText('Apartado en Ferretería Oriente')).toBeInTheDocument();
    expect(screen.getByRole('list', { name: 'Cronograma de cuotas' })).toBeInTheDocument();
    expect(screen.getByText('Vence en 2 días')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Próximo pago' })).toHaveTextContent('La cuota 1: $30.00');
    expect(screen.getByRole('region', { name: 'Dónde pagar' })).toBeInTheDocument();
});

/** `REQ-LAY-008` RB-1 y RB-2 · liquidar no tiene descuento, y el equivalente en bolívares puede moverse. */
it('avisa de que liquidar no tiene descuento y de que los bolívares se fijan al pagar', () => {
    const { rerender } = render(<LayawayShow {...props()} />);

    const next = screen.getByRole('region', { name: 'Próximo pago' });
    expect(next).toHaveTextContent('Sin descuento por adelantar.');
    expect(next).not.toHaveTextContent('puede variar');

    rerender(
        <LayawayShow
            {...props({
                nextPaymentSuggestion: {
                    kind: 'installment',
                    installmentNumber: 1,
                    amount: money('$30.00'),
                    payOff: { cents: centsUsd(15_000), usd: '$150.00', ves: 'Bs. 6.375,00' },
                },
            })}
        />,
    );

    expect(screen.getByRole('region', { name: 'Próximo pago' })).toHaveTextContent('puede variar el día que pagues');
});

/** RB-6 · esperando la inicial: instrucciones y plazo, sin cronograma. */
it('esperando la inicial, enseña la inicial y el plazo en lugar del cronograma', () => {
    render(
        <LayawayShow
            {...props(
                { installments: [] },
                { status: 'pending_down_payment', statusLabel: 'Esperando la inicial', expiresAt: '13 de septiembre a las 10:00 am' },
            )}
        />,
    );

    expect(screen.getByRole('region', { name: 'Inicial' })).toHaveTextContent('tienes hasta el 13 de septiembre a las 10:00 am');
    expect(screen.queryByRole('list', { name: 'Cronograma de cuotas' })).toBeNull();
    expect(screen.getByRole('region', { name: 'Dónde pagar' })).toBeInTheDocument();
});

/** SP-4 · cancelado: solo lectura y texto neutro; sin dónde pagar ni próximo pago. */
it('cancelado, solo lectura con el texto neutro', () => {
    render(
        <LayawayShow
            {...props(
                { nextPaymentSuggestion: null },
                {
                    status: 'cancelled',
                    statusLabel: 'Cancelado',
                    isTerminal: true,
                    acceptsPayments: false,
                    statusMessage: 'Este apartado fue cancelado por el negocio. Si tienes dudas, escríbele.',
                },
            )}
        />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('cancelado por el negocio');
    expect(screen.queryByRole('region', { name: 'Dónde pagar' })).toBeNull();
    expect(screen.queryByRole('region', { name: 'Próximo pago' })).toBeNull();
});

it('enlaza el catálogo de origen solo si sigue abierto', () => {
    const { rerender } = render(<LayawayShow {...props()} />);
    expect(screen.queryByText(/Ver el catálogo/)).toBeNull();

    rerender(<LayawayShow {...props({ catalogUrl: '/c/ofertas' })} />);
    expect(screen.getByRole('link', { name: 'Ver el catálogo de Ferretería Oriente' })).toHaveAttribute('href', '/c/ofertas');
});
