import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { ReceiptHistory } from '@/features/receipts/components/ReceiptHistory';
import { centsUsd } from '@/lib/money';
import { type PaymentRow, type ReceiptRow } from '@/types/receipt';
import { render, screen, within } from '@testing-library/react';
import { expect, it } from 'vitest';

const PAYMENT: PaymentRow = {
    settledAt: '12 de septiembre de 2026',
    amountUsd: { cents: centsUsd(6_000), usd: '$60,00', ves: null },
    declaredAmount: 'Bs. 2.550,00',
    rateApplied: '42.50000000',
    rateSource: 'bcv',
    rateSourceLabel: 'BCV',
    method: 'Pago móvil',
    appliedTo: [1, 2],
    downPayment: null,
    unallocated: null,
    registeredByBusiness: false,
    note: null,
};

function receipt(overrides: Partial<ReceiptRow> = {}): ReceiptRow {
    return {
        id: 'b3c1',
        submittedAt: '2026-09-07',
        method: 'Pago móvil',
        reference: '00042',
        declaredAmount: 'Bs. 850,00',
        paidToAccount: 'Pago móvil · Banesco · 0414-1234567',
        status: 'pending_review',
        statusLabel: 'En revisión',
        rejectionReason: null,
        fileUrl: '/mi-cuenta/comprobantes/9/archivo?signature=x',
        replacesReceiptId: null,
        ...overrides,
    };
}

it('dice cada pago con su tasa del día y las cuotas que amortizó', () => {
    render(<PaymentHistory payments={[PAYMENT]} empty="—" />);

    expect(screen.getByText('$60,00')).toBeInTheDocument();
    expect(screen.getByText('Bs. 2.550,00 · tasa 42,50 (BCV), la del día del pago · cuotas 1 y 2')).toBeInTheDocument();
    expect(screen.queryByText('Registrado por el negocio')).not.toBeInTheDocument();
});

it('marca el pago registrado por el negocio, con su nota', () => {
    render(
        <PaymentHistory
            payments={[
                { ...PAYMENT, declaredAmount: '$30,00', rateApplied: null, appliedTo: [3], registeredByBusiness: true, note: 'Efectivo en tienda.' },
            ]}
            empty="—"
        />,
    );

    expect(screen.getByText('$30,00 · cuota 3')).toBeInTheDocument();
    expect(screen.getByText('Registrado por el negocio')).toBeInTheDocument();
    expect(screen.getByText('Efectivo en tienda.')).toBeInTheDocument();
});

it('dice qué parte fue a la inicial y qué sobró', () => {
    render(
        <PaymentHistory
            payments={[{ ...PAYMENT, declaredAmount: '$80,00', rateApplied: null, appliedTo: [1], downPayment: '$50,00', unallocated: '$0,50' }]}
            empty="—"
        />,
    );

    expect(screen.getByText('$80,00 · inicial de $50,00, cuota 1, $0,50 sin asignar')).toBeInTheDocument();
});

it('sin pagos, lo dice', () => {
    render(<PaymentHistory payments={[]} empty="Todavía no hay pagos." />);

    expect(screen.getByText('Todavía no hay pagos.')).toBeInTheDocument();
});

it('dice el motivo del rechazo, encadena el reintento y enlaza el archivo', () => {
    const rejected = receipt({
        id: 'a1',
        submittedAt: '2026-09-05',
        status: 'rejected',
        statusLabel: 'Rechazado',
        rejectionReason: 'La referencia no coincide con el banco.',
    });
    const retry = receipt({ id: 'b2', replacesReceiptId: 'a1' });

    render(<ReceiptHistory receipts={[retry, rejected]} />);

    const [first, second] = screen.getAllByRole('listitem');
    expect(within(first!).getByText('Reintento del comprobante del 05/09/2026')).toBeInTheDocument();
    expect(within(first!).getByText('Pagado a Pago móvil · Banesco · 0414-1234567')).toBeInTheDocument();
    expect(within(first!).getByRole('link', { name: 'Ver el comprobante' })).toHaveAttribute('href', '/mi-cuenta/comprobantes/9/archivo?signature=x');
    expect(within(second!).getByText('Motivo: La referencia no coincide con el banco.')).toBeInTheDocument();
});
