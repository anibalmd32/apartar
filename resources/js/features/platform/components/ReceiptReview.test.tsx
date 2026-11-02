import { ReceiptReview } from '@/features/platform/components/ReceiptReview';
import { type CentsUsd } from '@/types/money';
import { type PendingSubscriptionReceipt } from '@/types/platform';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

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

it('el importe conciliado tiene etiqueta visible y la tasa va en su propia fila', () => {
    render(<ReceiptReview receipt={RECEIPT} />);

    const amount = screen.getByLabelText('Importe conciliado');
    const rate = screen.getByLabelText('Tasa (opcional)');

    expect(screen.getByText('Importe conciliado')).toBeVisible();
    expect(screen.getByText('Tasa (opcional)')).toBeVisible();
    expect(amount).toHaveValue('15,00');
    expect(amount.closest('.grid')?.contains(screen.getByLabelText('Moneda'))).toBe(true);
    expect(amount.closest('.grid')?.contains(rate)).toBe(false);
});
