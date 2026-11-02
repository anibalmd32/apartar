import { PaymentFields } from '@/features/receipts/components/PaymentFields';
import { emptyPaymentValues, paymentMissing, type PaymentFieldValues } from '@/features/receipts/lib/payment-fields';
import { MANUAL_PAYMENT_OPTIONS } from '@/test/manual-payment-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: {} }) }));

const IDLE = { amount: '', currency: 'USD', rate: '', preview: null, error: null, loading: false };

function Harness({ initial = {} }: { initial?: Partial<PaymentFieldValues> }) {
    const [values, setValues] = useState<PaymentFieldValues>({ ...emptyPaymentValues(MANUAL_PAYMENT_OPTIONS), ...initial });

    return (
        <PaymentFields
            values={values}
            errors={{}}
            onChange={(patch) => setValues((current) => ({ ...current, ...patch }))}
            options={MANUAL_PAYMENT_OPTIONS}
            preview={IDLE}
        />
    );
}

function renderFields(initial: Partial<PaymentFieldValues> = {}) {
    return render(
        <QueryClientProvider client={new QueryClient()}>
            <Harness initial={initial} />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(() => new Promise(() => undefined)),
    );
});

it('pide la moneda antes del importe y muestra su símbolo', () => {
    renderFields({ method: 'efectivo_usd' });
    const currency = screen.getByLabelText('Moneda');
    const amount = screen.getByLabelText('Importe');

    expect(currency.compareDocumentPosition(amount) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(amount.closest('label')).toHaveTextContent('$');

    fireEvent.change(currency, { target: { value: 'VES' } });
    expect(screen.getByLabelText('Importe').closest('label')).toHaveTextContent('Bs.');
});

it('al salir del importe lo completa con dos decimales', () => {
    renderFields({ method: 'efectivo_usd' });
    const amount = screen.getByLabelText('Importe');

    fireEvent.change(amount, { target: { value: '14,5' } });
    fireEvent.blur(amount);

    expect(amount).toHaveValue('14,50');
});

it('la referencia abre el teclado numérico y la nota se marca obligatoria', () => {
    renderFields({ method: 'pago_movil', currency: 'VES' });

    expect(screen.getByLabelText('Número de referencia')).toHaveAttribute('inputmode', 'numeric');
    expect(screen.getByLabelText('Nota (obligatoria)')).toBeRequired();
});

it('el recuadro vacío de la vista previa no usa el color de éxito', () => {
    renderFields({ method: 'efectivo_usd' });

    const box = screen.getByRole('region', { name: 'Vista previa del pago' });
    expect(box).toHaveTextContent('Escribe el importe para ver a qué cuotas iría.');
    expect(box).not.toHaveClass('bg-success-soft');
});

it('dice qué le falta al pago', () => {
    const values = emptyPaymentValues(MANUAL_PAYMENT_OPTIONS);

    expect(paymentMissing(MANUAL_PAYMENT_OPTIONS, values, IDLE)).toBe('Elige el método del pago.');
    expect(paymentMissing(MANUAL_PAYMENT_OPTIONS, { ...values, method: 'pago_movil' }, IDLE)).toBe(
        'Escribe el banco y el número de referencia del pago.',
    );
    expect(paymentMissing(MANUAL_PAYMENT_OPTIONS, { ...values, method: 'efectivo_usd' }, IDLE)).toBe(
        'Escribe la nota del pago, de al menos 10 caracteres.',
    );
    expect(paymentMissing(MANUAL_PAYMENT_OPTIONS, { ...values, method: 'efectivo_usd', note: 'Recibido en tienda.' }, IDLE)).toBeNull();
});
