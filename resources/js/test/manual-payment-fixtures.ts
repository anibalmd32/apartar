import { type ManualPaymentOptions } from '@/types/layaway-backoffice';
import { act, fireEvent, screen } from '@testing-library/react';

export const MANUAL_PAYMENT_OPTIONS: ManualPaymentOptions = {
    storeUrl: '/backoffice/apartados/AP-7K3M9QRT/pagos',
    previewUrl: '/backoffice/apartados/AP-7K3M9QRT/pagos/vista-previa',
    methods: [
        { value: 'efectivo_usd', label: 'Efectivo en dólares', defaultCurrency: 'USD', requiresReference: false },
        { value: 'pago_movil', label: 'Pago móvil', defaultCurrency: 'VES', requiresReference: true },
    ],
    paymentAccounts: [],
    currencies: [
        { value: 'USD', label: 'Dólares' },
        { value: 'VES', label: 'Bolívares' },
    ],
    paidAt: { min: '2026-09-10', max: '2026-09-11' },
    rate: { rate: '42.50000000', sourceLabel: 'BCV' },
    file: { accept: '.jpg,.jpeg,.png,.webp,.pdf', maxMb: 5 },
    readUrl: '/backoffice/pagos/leer-comprobante?desde=2026-09-01&hasta=2026-10-05',
};

export function chooseBank(name: string) {
    const input = screen.getByLabelText('Banco');
    act(() => fireEvent.change(input, { target: { value: name } }));
    act(() => fireEvent.keyDown(input, { key: 'ArrowDown' }));
    act(() => fireEvent.keyDown(input, { key: 'Enter' }));
}
