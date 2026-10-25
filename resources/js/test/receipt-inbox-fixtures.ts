import { type TableMeta } from '@/types/data-table';
import { type PaymentAccountView } from '@/types/payment';
import { type ReceiptInboxProps, type ReceiptInboxRow } from '@/types/receipt';

export const INBOX_NOW = '2026-10-07T12:00:00-04:00';

export const INACTIVE_ACCOUNT: PaymentAccountView = {
    id: 7,
    method: 'pago_movil',
    methodLabel: 'Pago móvil',
    identifierLabel: 'Teléfono',
    bank: 'Banesco',
    holderName: 'Tienda',
    holderDocument: 'J123456789',
    identifier: '0414-1234567',
    instructions: null,
    isActive: false,
    position: 1,
};

export function inboxRow(overrides: Partial<ReceiptInboxRow> = {}): ReceiptInboxRow {
    return {
        id: 1,
        reviewUrl: '/backoffice/comprobantes/1',
        status: 'pending_review',
        statusLabel: 'En revisión',
        overdue: false,
        submittedAt: '2026-10-07T09:00:00-04:00',
        paidAt: '2026-10-06',
        layaway: { code: 'AP-7K3M9QRT', url: '/backoffice/apartados/AP-7K3M9QRT', cancelled: false },
        customer: { fullName: 'María González', documentNumber: 'V12345678' },
        method: 'pago_movil',
        methodLabel: 'Pago móvil',
        bank: 'Mercantil',
        reference: '00042',
        paymentAccount: INACTIVE_ACCOUNT,
        declaredAmount: '1275.50',
        declaredCurrency: 'VES',
        file: { url: '/backoffice/comprobantes/1/archivo?signature=abc', kind: 'image' },
        ...overrides,
    };
}

export const INBOX_META: TableMeta = {
    page: 1,
    perPage: 25,
    sort: null,
    total: null,
    lastPage: null,
    hasMore: false,
    filters: { status: null, method: null, bank: null, account: null, from: null, to: null, cancelled: null },
    ignoredFilters: [],
};

export function inboxProps(rows: ReceiptInboxRow[], meta: TableMeta = INBOX_META): ReceiptInboxProps {
    return {
        rows,
        meta,
        options: {
            statuses: [
                { value: 'pending_review', label: 'En revisión' },
                { value: 'approved', label: 'Aprobado' },
                { value: 'rejected', label: 'Rechazado' },
            ],
            methods: [{ value: 'pago_movil', label: 'Pago móvil' }],
            banks: ['Mercantil'],
            paymentAccounts: [INACTIVE_ACCOUNT],
        },
        newestPending: 40,
        now: INBOX_NOW,
        overdueAfterHours: 48,
        timezone: 'Asia/Tokyo',
    };
}
