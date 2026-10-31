import { centsUsd } from '@/lib/money';
import { type PaymentAccountView } from '@/types/payment';
import { type ReceiptCreateProps } from '@/types/receipt';
import { afterEach, beforeEach, vi } from 'vitest';

export const PAGO_MOVIL: PaymentAccountView = {
    id: 7,
    method: 'pago_movil',
    methodLabel: 'Pago móvil',
    identifierLabel: 'Teléfono',
    bank: 'Banesco',
    holderName: 'Ferretería Oriente C.A.',
    holderDocument: 'J-12345678-9',
    identifier: '0414-1234567',
    instructions: null,
    isActive: true,
    position: 1,
};

export const ZELLE: PaymentAccountView = {
    ...PAGO_MOVIL,
    id: 9,
    method: 'zelle',
    methodLabel: 'Zelle',
    identifierLabel: 'Correo',
    bank: null,
    identifier: 'pagos@oriente.com',
    position: 2,
};

export function receiptProps(overrides: Partial<ReceiptCreateProps> = {}): ReceiptCreateProps {
    return {
        business: { name: 'Ferretería Oriente', slug: 'ferreteria', logoUrl: null, brandColor: null, brandingEnabled: false },
        layaway: {
            code: 'AP-7K3M9QRT',
            productName: 'Nevera Mabe',
            statusLabel: 'Al día',
            remaining: { cents: centsUsd(15_000), usd: '$150,00', ves: null },
        },
        suggestion: {
            kind: 'installment',
            installmentNumber: 2,
            amount: { cents: centsUsd(3_000), usd: '$30,00', ves: null },
            started: false,
            amountUsd: '30.00',
            amountVes: '1275.00',
        },
        paymentAccounts: [PAGO_MOVIL],
        methods: [
            { value: 'pago_movil', label: 'Pago móvil', isCash: false, requiresReference: true, defaultCurrency: 'VES' },
            { value: 'zelle', label: 'Zelle', isCash: false, requiresReference: true, defaultCurrency: 'USD' },
            { value: 'efectivo_usd', label: 'Efectivo en dólares', isCash: true, requiresReference: false, defaultCurrency: 'USD' },
            { value: 'efectivo_ves', label: 'Efectivo en bolívares', isCash: true, requiresReference: false, defaultCurrency: 'VES' },
        ],
        currencies: [
            { value: 'USD', label: 'USD' },
            { value: 'VES', label: 'Bs.' },
        ],
        paidAt: { min: '2026-09-10', max: '2026-10-05' },
        file: { maxMb: 5, accept: '.jpg,.jpeg,.png,.webp,.pdf' },
        storeUrl: '/mi-cuenta/apartados/AP-7K3M9QRT/comprobante',
        readUrl: '/mi-cuenta/apartados/AP-7K3M9QRT/comprobante/leer',
        backUrl: '/mi-cuenta/apartados/AP-7K3M9QRT',
        replaces: null,
        ...overrides,
    };
}

export function stubObjectUrls() {
    const mocks = { createObjectURL: vi.fn<(file: Blob) => string>(), revokeObjectURL: vi.fn<(url: string) => void>() };
    const original = { createObjectURL: URL.createObjectURL, revokeObjectURL: URL.revokeObjectURL };

    beforeEach(() => {
        let created = 0;
        mocks.createObjectURL.mockReset().mockImplementation(() => `blob:captura-${++created}`);
        mocks.revokeObjectURL.mockReset();
        Object.assign(URL, mocks);
    });

    afterEach(() => {
        Object.assign(URL, original);
    });

    return mocks;
}

export function fakeFile(name: string, type: string, size = 300 * 1024): File {
    const file = new File(['x'], name, { type });
    Object.defineProperty(file, 'size', { value: size });

    return file;
}
