import { centsUsd } from '@/lib/money';
import SubscriptionInvoices from '@/pages/backoffice/subscription/Invoices';
import { type InvoiceRow, type InvoicesPageProps } from '@/types/invoice';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

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
        number: 'F-2026-0001',
        planName: 'Pro',
        kind: 'Renovación',
        periodStart: '2026-11-01T04:00:00Z',
        periodEnd: '2026-12-01T04:00:00Z',
        amount: { cents: centsUsd(1500), usd: '$15,00', ves: 'Bs. 637,50' },
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
        platformAccounts: [{ method: 'Zelle', detail: 'pagos@apartar.app' }],
    };
}

it('enseña el importe en las dos monedas y pide referencia solo cuando hace falta', () => {
    render(<SubscriptionInvoices {...props([invoice()])} />);

    expect(screen.getAllByText('F-2026-0001').length).toBeGreaterThan(0);
    expect(screen.getByText('≈ Bs. 637,50')).toBeInTheDocument();
    expect(screen.getByText('$15,00 · Bs. 637,50 a tasa BCV')).toBeInTheDocument();
    expect(screen.getByText('pagos@apartar.app')).toBeInTheDocument();
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
                invoice({ id: 1, number: 'F-2026-0001', status: 'paid', payUrl: null }),
                invoice({ id: 2, number: 'F-2026-0002', status: 'under_review', receiptStatus: 'pending_review' }),
                invoice({ id: 3, number: 'F-2026-0003', status: 'pending', receiptStatus: 'rejected', rejectionReason: 'Ilegible' }),
            ])}
        />,
    );

    const panel = screen.getByRole('region', { name: 'Pagar F-2026-0003' });

    expect(within(panel).getByText(/Rechazamos tu último comprobante: Ilegible/)).toBeInTheDocument();
    expect(screen.getByText('Aprobado')).toBeInTheDocument();
    expect(screen.getByText('En revisión', { selector: 'span.font-medium' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pagar F-2026-0001' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Pagar F-2026-0002' })).toBeNull();
});

it('lleva al panel la factura que se elige en la tabla', () => {
    render(
        <SubscriptionInvoices
            {...props([invoice({ id: 1, number: 'F-2026-0001', status: 'pending' }), invoice({ id: 2, number: 'F-2026-0002', status: 'overdue' })])}
        />,
    );

    expect(screen.getByRole('region', { name: 'Pagar F-2026-0001' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Pagar F-2026-0002' }));

    expect(screen.getByRole('region', { name: 'Pagar F-2026-0002' })).toBeInTheDocument();
});

it('precarga lo que falta tras un pago parcial', () => {
    render(<SubscriptionInvoices {...props([invoice({ status: 'pending', outstanding: { cents: centsUsd(500), usd: '$5,00', ves: null } })])} />);

    expect(screen.getByText('Falta por pagar: $5,00')).toBeInTheDocument();
    expect(screen.getByLabelText('Importe')).toHaveValue('5.00');
});

it('dice que en el plan Gratis no hay facturas', () => {
    render(<SubscriptionInvoices {...props([])} />);

    expect(screen.getByRole('alert')).toHaveTextContent('en el plan Gratis no se factura nada');
});
