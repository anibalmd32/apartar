import { centsUsd } from '@/lib/money';
import SubscriptionInvoices from '@/pages/backoffice/subscription/Invoices';
import { type InvoiceRow, type InvoicesPageProps } from '@/types/invoice';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * El historial de facturas de la suscripción (`REQ-SUB-006` RB-1, RB-4,
 * RB-7, `T-069`): el importe en las dos monedas, el estado, el motivo del
 * rechazo, y el formulario solo donde el servidor dijo que se paga.
 */

const post = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        usePage: () => ({ url: '/backoffice/suscripcion/facturas', props: { errors: {} } }),
        Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
            <a href={href} className={className}>
                {children}
            </a>
        ),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setState] = React.useState(initial);

            return {
                data,
                setData: (key: string, value: unknown) => setState((current) => ({ ...current, [key]: value })),
                post: (...args: unknown[]) => post(data, ...args),
                processing: false,
                errors: {},
            };
        },
    };
});

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

beforeEach(() => post.mockReset());

function invoice(overrides: Partial<InvoiceRow> = {}): InvoiceRow {
    return {
        id: 1,
        planName: 'Pro',
        kind: 'Renovación',
        periodStart: '2026-11-01T04:00:00Z',
        periodEnd: '2026-12-01T04:00:00Z',
        amount: { cents: centsUsd(1500), usd: '$15.00', ves: 'Bs. 637,50' },
        outstanding: null,
        status: 'overdue',
        dueDate: '2026-11-01',
        receiptStatus: 'none',
        rejectionReason: null,
        payUrl: '/backoffice/suscripcion/facturas/1/comprobante',
        ...overrides,
    };
}

function props(invoices: InvoiceRow[]): InvoicesPageProps {
    return {
        invoices,
        methods: [
            { value: 'pago_movil', label: 'Pago móvil', requiresReference: true, defaultCurrency: 'VES' },
            { value: 'efectivo_usd', label: 'Efectivo en dólares', requiresReference: false, defaultCurrency: 'USD' },
        ],
        currencies: [
            { value: 'USD', label: 'Dólares' },
            { value: 'VES', label: 'Bolívares' },
        ],
        file: { maxMb: 5, accept: '.jpg,.png,.pdf' },
        subscriptionUrl: '/backoffice/suscripcion',
    };
}

it('enseña el importe en las dos monedas y pide referencia solo cuando hace falta', () => {
    render(<SubscriptionInvoices {...props([invoice()])} />);

    expect(screen.getByText('$15.00')).toBeInTheDocument();
    expect(screen.getByText('≈ Bs. 637,50')).toBeInTheDocument();
    expect(screen.getByText('Vencida')).toBeInTheDocument();
    expect(screen.getByLabelText('Referencia')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Cómo pagaste'), { target: { value: 'efectivo_usd' } });

    expect(screen.queryByLabelText('Referencia')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Enviar comprobante' }));

    expect(post).toHaveBeenCalledWith(
        expect.objectContaining({ method: 'efectivo_usd', amount: '15.00' }),
        '/backoffice/suscripcion/facturas/1/comprobante',
        {
            forceFormData: true,
            preserveScroll: true,
        },
    );
});

it('no ofrece pagar lo que ya no se paga ni lo que está en revisión, y dice por qué se rechazó', () => {
    render(
        <SubscriptionInvoices
            {...props([
                invoice({ id: 1, status: 'paid', payUrl: null }),
                invoice({ id: 2, status: 'under_review', receiptStatus: 'pending_review' }),
                invoice({ id: 3, status: 'pending', receiptStatus: 'rejected', rejectionReason: 'Ilegible' }),
            ])}
        />,
    );

    const cards = screen.getAllByRole('article');

    expect(within(cards[0]!).queryByRole('button')).toBeNull();
    expect(within(cards[1]!).getByText('Tu comprobante está en revisión.')).toBeInTheDocument();
    expect(within(cards[1]!).queryByRole('button')).toBeNull();
    expect(within(cards[2]!).getByText(/Rechazamos tu último comprobante: Ilegible/)).toBeInTheDocument();
    expect(within(cards[2]!).getByRole('button', { name: 'Enviar comprobante' })).toBeInTheDocument();
});

/** SP-2 · tras un pago parcial se dice lo que falta, y el importe se precarga con eso. */
it('precarga lo que falta tras un pago parcial', () => {
    render(<SubscriptionInvoices {...props([invoice({ status: 'pending', outstanding: { cents: centsUsd(500), usd: '$5.00', ves: null } })])} />);

    expect(screen.getByText('Falta por pagar: $5.00')).toBeInTheDocument();
    expect(screen.getByLabelText('Importe')).toHaveValue('5.00');
});

it('dice que en Free no hay facturas', () => {
    render(<SubscriptionInvoices {...props([])} />);

    expect(screen.getByRole('alert')).toHaveTextContent('en Free no se factura nada');
});
