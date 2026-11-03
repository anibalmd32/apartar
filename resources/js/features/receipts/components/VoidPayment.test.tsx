import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { centsUsd } from '@/lib/money';
import { type PaymentRow } from '@/types/receipt';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { post, formErrors } = vi.hoisted(() => ({ post: vi.fn(), formErrors: { current: {} as Record<string, string> } }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);

            return {
                data,
                setData: (key: keyof T, value: unknown) => setState((previous) => ({ ...previous, [key]: value })),
                reset: () => setState(initial),
                clearErrors: vi.fn(),
                errors: formErrors.current,
                processing: false,
                post: (url: string) => post(url, data),
            };
        },
    };
});

const PAYMENT: PaymentRow = {
    settledAt: '12 sept 2026',
    amountUsd: { cents: centsUsd(6_000), usd: '$60,00', ves: null },
    declaredAmount: '$60,00',
    rateApplied: null,
    rateSource: 'bcv',
    rateSourceLabel: 'BCV',
    method: 'Pago móvil',
    appliedTo: [1, 2],
    downPayment: null,
    unallocated: null,
    registeredByBusiness: false,
    note: null,
    bankReference: null,
    attachmentUrl: null,
    fromReceipt: true,
    voided: null,
    voidUrl: '/backoffice/pagos/7/anular',
};

beforeEach(() => {
    post.mockReset();
    formErrors.current = {};
});

function openSheet(payment: PaymentRow = PAYMENT, reopens = false) {
    render(<PaymentHistory payments={[payment]} empty="—" reopens={reopens} />);
    fireEvent.click(screen.getByRole('button', { name: 'Acciones del pago de $60,00' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Anular pago' }));

    return screen.getByRole('dialog');
}

it('cuenta qué pasa al anular y envía el motivo', () => {
    const sheet = openSheet(PAYMENT, true);
    const effects = within(sheet).getByRole('list', { name: 'Qué pasa al anular' });

    expect(within(effects).getByText('Se descuenta de lo que cubrió: cuotas 1 y 2.')).toBeInTheDocument();
    expect(within(effects).getByText('El comprobante vuelve a la bandeja, pendiente de revisión.')).toBeInTheDocument();
    expect(within(effects).getByText('El apartado estaba pagado: se reabre con el saldo que vuelve a deber.')).toBeInTheDocument();

    fireEvent.change(within(sheet).getByLabelText(/Motivo/), { target: { value: 'El comprobante era de otro cliente.' } });
    fireEvent.click(within(sheet).getByRole('button', { name: 'Anular pago' }));

    expect(post).toHaveBeenCalledWith('/backoffice/pagos/7/anular', { reason: 'El comprobante era de otro cliente.' });
});

it('no promete reabrir ni devolver un comprobante que no hay', () => {
    const sheet = openSheet({ ...PAYMENT, fromReceipt: false });

    expect(within(sheet).queryByText(/vuelve a la bandeja/)).not.toBeInTheDocument();
    expect(within(sheet).queryByText(/se reabre/)).not.toBeInTheDocument();
});

it('muestra el rechazo del servidor', () => {
    formErrors.current = {
        payment: 'Este pago cubrió la inicial: para anularlo, registra antes el pago correcto de la inicial o cancela el apartado.',
    };
    const sheet = openSheet();

    expect(within(sheet).getByRole('alert')).toHaveTextContent('Este pago cubrió la inicial');
});

it('deja el pago anulado tachado, con quién, cuándo y por qué, y sin acciones', () => {
    render(
        <PaymentHistory
            payments={[{ ...PAYMENT, appliedTo: [], voidUrl: null, voided: { at: '13 sept 2026', by: 'Ana Pérez', reason: 'Era de otro cliente.' } }]}
            empty="—"
        />,
    );

    expect(screen.getByText('Anulado')).toBeInTheDocument();
    expect(screen.getByText('$60,00')).toHaveClass('line-through');
    expect(screen.getByText('Anulado el 13 sept 2026 por Ana Pérez. Motivo: Era de otro cliente.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Acciones del pago/ })).not.toBeInTheDocument();
});
