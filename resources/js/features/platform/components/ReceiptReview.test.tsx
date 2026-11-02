import { ReceiptReview } from '@/features/platform/components/ReceiptReview';
import { type CentsUsd } from '@/types/money';
import { type PendingSubscriptionReceipt } from '@/types/platform';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { formErrors } = vi.hoisted(() => ({ formErrors: { current: {} as Record<string, string> } }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);

            return {
                data,
                setData: (key: keyof T, value: unknown) => setState((previous) => ({ ...previous, [key]: value })),
                errors: formErrors.current,
                processing: false,
                post: vi.fn(),
            };
        },
    };
});

beforeEach(() => {
    formErrors.current = {};
});

const RECEIPT: PendingSubscriptionReceipt = {
    id: 2,
    organization: { id: 5, name: 'Tecno Hogar' },
    invoiceId: 9,
    plan: 'pro',
    outstanding: { cents: 1500 as CentsUsd, usd: '$15,00', ves: null },
    declared: '15.00 USD',
    method: 'Zelle',
    bank: null,
    reference: 'ZL662514',
    paidAt: '2026-10-12',
    submittedAt: null,
    isLate: false,
    fileUrl: '/plataforma/comprobantes/2/archivo',
};

it('el importe va solo a lo ancho y la moneda y la tasa debajo, con etiquetas visibles', () => {
    render(<ReceiptReview receipt={RECEIPT} />);

    const amount = screen.getByLabelText('Importe conciliado');
    const currency = screen.getByLabelText('Moneda');
    const rate = screen.getByLabelText('Tasa (opcional)');

    for (const label of ['Importe conciliado', 'Moneda', 'Tasa (opcional)']) {
        expect(screen.getByText(label)).toBeVisible();
    }
    expect(amount).toHaveValue('15,00');
    expect(amount.closest('.grid')).toBeNull();
    expect(currency.closest('.grid')?.contains(rate)).toBe(true);
});

it('cada error aparece debajo de su campo', () => {
    formErrors.current = { settledAmount: 'El importe no cuadra.', rate: 'La tasa no es válida.' };
    render(<ReceiptReview receipt={RECEIPT} />);

    expect(screen.getByLabelText('Importe conciliado')).toHaveAccessibleDescription('El importe no cuadra.');
    expect(screen.getByLabelText('Tasa (opcional)')).toHaveAccessibleDescription('La tasa no es válida.');
});

it('aprobar y rechazar son botones claros, no un segmentado', () => {
    render(<ReceiptReview receipt={RECEIPT} />);

    expect(screen.queryByRole('button', { pressed: false })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Aprobar pago' })).toHaveClass('h-12');

    fireEvent.click(screen.getByRole('button', { name: 'Rechazar' }));

    expect(screen.getByLabelText('Motivo del rechazo (el negocio lo verá)')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Rechazar comprobante' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Volver a aprobar' }));
    expect(screen.getByLabelText('Importe conciliado')).toBeInTheDocument();
});
