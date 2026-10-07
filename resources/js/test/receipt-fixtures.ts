import { centsUsd } from '@/lib/money';
import { type PaymentAccountView } from '@/types/payment';
import { type ReceiptCreateProps } from '@/types/receipt';
import { afterEach, beforeEach, vi } from 'vitest';

/**
 * Props de ejemplo de `pages/portal/ReceiptCreate.tsx` (`REQ-PAY-001`), con la
 * forma exacta que compone el servidor. Las comparten los tests de la página,
 * del hook y de sus piezas.
 */

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
            remaining: { cents: centsUsd(15_000), usd: '$150.00', ves: null },
        },
        suggestion: {
            kind: 'installment',
            installmentNumber: 2,
            amount: { cents: centsUsd(3_000), usd: '$30.00', ves: null },
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
        backUrl: '/mi-cuenta/apartados/AP-7K3M9QRT',
        ...overrides,
    };
}

/**
 * Dobles de `URL.createObjectURL()` y `URL.revokeObjectURL()`, que jsdom no
 * trae. Se llama en lo alto del archivo de tests: pone los dobles antes de cada
 * test —cada URL creada es `blob:captura-N`, contando desde 1— y deja `URL`
 * como estaba después, para que no se filtren a otros archivos.
 */
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

/** Un archivo del tamaño pedido sin reservar la memoria: `size` es lo único que se mira. */
export function fakeFile(name: string, type: string, size = 300 * 1024): File {
    const file = new File(['x'], name, { type });
    Object.defineProperty(file, 'size', { value: size });

    return file;
}
