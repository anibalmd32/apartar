import { AllocationEditor } from '@/features/receipts/components/AllocationEditor';
import { type ReallocationOptions } from '@/types/layaway-backoffice';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * El editor del reparto (`REQ-PAY-009` RB-8, `T-063`): enseña el reparto
 * actual, deja editarlo cuota por cuota y no envía hasta que la suma cuadra,
 * ninguna cuota se sobrepaga y hay motivo.
 *
 * `useForm` se sustituye por uno con estado de verdad que apunta lo que se
 * envía.
 */

const { patch, formErrors } = vi.hoisted(() => ({ patch: vi.fn(), formErrors: { current: {} as Record<string, string> } }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);
            const setData = (key: keyof T, value: unknown) => setState((previous) => ({ ...previous, [key]: value }));

            return {
                data,
                setData,
                errors: formErrors.current,
                processing: false,
                clearErrors: () => undefined,
                reset: () => undefined,
                patch: (url: string) => patch(url, data),
            };
        },
    };
});

/** $60 repartidos $30, $15 y $15; la cuota 2 tiene además $15 de otro pago, así que de este admite $15. */
const OPTIONS: ReallocationOptions = {
    installments: [
        { id: 11, number: 1, amountCents: 3_000, paidCents: 3_000 },
        { id: 12, number: 2, amountCents: 3_000, paidCents: 3_000 },
        { id: 13, number: 3, amountCents: 3_000, paidCents: 1_500 },
    ],
    payments: [
        {
            id: 7,
            url: '/backoffice/pagos/7/reasignar',
            label: '$60.00 del 10 de septiembre',
            allocations: [
                { installmentId: 11, cents: 3_000 },
                { installmentId: 12, cents: 1_500 },
                { installmentId: 13, cents: 1_500 },
            ],
        },
    ],
};

beforeEach(() => {
    patch.mockReset();
    formErrors.current = {};
});

it('parte del reparto actual y envía el nuevo con su motivo', () => {
    render(<AllocationEditor options={OPTIONS} />);

    expect(screen.getByLabelText('Importe para la cuota 1')).toHaveValue('30.00');
    expect(screen.getByText('El reparto suma $60.00, lo mismo que el pago tiene repartido.')).toBeInTheDocument();
    const submit = screen.getByRole('button', { name: 'Reasignar el pago' });
    expect(submit).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Importe para la cuota 2'), { target: { value: '' } });
    fireEvent.change(screen.getByLabelText('Importe para la cuota 3'), { target: { value: '30' } });
    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'El cliente pactó la cuota 3.' } });
    fireEvent.click(submit);

    expect(patch).toHaveBeenCalledWith('/backoffice/pagos/7/reasignar', {
        allocations: [
            { installment: 11, amount: '30.00' },
            { installment: 12, amount: '' },
            { installment: 13, amount: '30' },
        ],
        reason: 'El cliente pactó la cuota 3.',
    });
});

it('no envía si la suma no cuadra o una cuota se sobrepaga, y dice la cifra', () => {
    render(<AllocationEditor options={OPTIONS} />);
    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'El cliente pactó otra cosa.' } });

    fireEvent.change(screen.getByLabelText('Importe para la cuota 3'), { target: { value: '5.00' } });
    expect(screen.getByText('Faltan $10.00: el reparto tiene que sumar $60.00.')).toBeInTheDocument();

    // La 2 admite lo que le daba este pago ($15) más lo que le falta ($0): $15.
    fireEvent.change(screen.getByLabelText('Importe para la cuota 2'), { target: { value: '25.00' } });
    expect(screen.getByText('La cuota 2 admite como mucho $15.00 de este pago.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reasignar el pago' })).toBeDisabled();
});

/** Con dos pagos repartidos, elegir otro carga su reparto y su total. */
it('al elegir otro pago carga su reparto', () => {
    const second = {
        id: 8,
        url: '/backoffice/pagos/8/reasignar',
        label: '$30.00 del 25 de septiembre',
        allocations: [
            { installmentId: 13, cents: 1_500 },
            { installmentId: 12, cents: 1_500 },
        ],
    };
    render(<AllocationEditor options={{ ...OPTIONS, payments: [...OPTIONS.payments, second] }} />);

    fireEvent.change(screen.getByLabelText('Pago'), { target: { value: '8' } });

    expect(screen.getByLabelText('Importe para la cuota 1')).toHaveValue('');
    expect(screen.getByLabelText('Importe para la cuota 3')).toHaveValue('15.00');
    expect(screen.getByText('El reparto suma $30.00, lo mismo que el pago tiene repartido.')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'El cliente pactó la cuota 3.' } });
    fireEvent.change(screen.getByLabelText('Importe para la cuota 2'), { target: { value: '' } });
    fireEvent.change(screen.getByLabelText('Importe para la cuota 3'), { target: { value: '30.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Reasignar el pago' }));

    expect(patch.mock.calls[0]?.[0]).toBe('/backoffice/pagos/8/reasignar');
});

/** Lo que rechaza el servidor se ve, sea del reparto o de una fila; y el motivo cuenta sin espacios. */
it('enseña el rechazo del servidor y no cuenta los espacios del motivo', () => {
    formErrors.current = { 'allocations.0.amount': 'El formato del importe de la cuota no es válido.' };
    render(<AllocationEditor options={OPTIONS} />);

    expect(screen.getByText('El formato del importe de la cuota no es válido.')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Importe para la cuota 2'), { target: { value: '' } });
    fireEvent.change(screen.getByLabelText('Importe para la cuota 3'), { target: { value: '30' } });
    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: '   Pactado         ' } });
    expect(screen.getByRole('button', { name: 'Reasignar el pago' })).toBeDisabled();
});
