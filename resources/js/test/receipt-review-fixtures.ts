import { centsUsd } from '@/lib/money';
import { type ApprovalPreviewView, type ReceiptReviewProps } from '@/types/receipt';

/**
 * Props de ejemplo de `pages/backoffice/receipts/Review.tsx` (`REQ-PAY-004`
 * RB-6, `REQ-PAY-005`), con la forma que compone `ReceiptReviewController`:
 * un comprobante de Bs. 2.550 de un apartado al día, con la vista previa de
 * lo declarado a la tasa vigente.
 */

const money = (usd: string, cents: number) => ({ cents: centsUsd(cents), usd, ves: null });

export const PREVIEW: ApprovalPreviewView = {
    amount: '$60.00',
    rate: '42.50000000',
    rateSource: 'bcv',
    rateSourceLabel: 'BCV',
    downPayment: null,
    allocations: [
        { number: 1, amount: '$30.00', covers: true },
        { number: 2, amount: '$30.00', covers: true },
    ],
    unallocated: null,
    unallocatedCents: 0,
    statusAfter: 'active',
    statusAfterLabel: 'Al día',
    summary: 'Se aplicará a las cuotas 1 y 2.',
};

export function reviewProps(overrides: Partial<ReceiptReviewProps> = {}): ReceiptReviewProps {
    return {
        receipt: {
            id: 9,
            status: 'pending_review',
            statusLabel: 'En revisión',
            submittedAt: '7 de octubre de 2026, 9:00 a. m.',
            paidAt: '2026-10-06',
            method: 'Pago móvil',
            bank: 'Mercantil',
            reference: '00042',
            note: null,
            declaredAmount: '2550.00',
            declaredCurrency: 'VES',
            declared: 'Bs. 2.550,00',
            paymentAccount: null,
            file: { url: '/backoffice/comprobantes/9/archivo?signature=x', kind: 'image' },
            reviewedBy: null,
            rejectionReason: null,
        },
        customer: { fullName: 'María González', documentNumber: 'V12345678', phone: '+584141234567' },
        layaway: {
            code: 'AP-7K3M9QRT',
            url: '/backoffice/apartados/AP-7K3M9QRT',
            status: 'active',
            statusLabel: 'Al día',
            productName: 'Nevera Mabe',
            total: money('$200.00', 20_000),
            downPayment: money('$50.00', 5_000),
            paid: money('$50.00', 5_000),
            remaining: money('$150.00', 15_000),
        },
        installments: [
            {
                number: 1,
                dueDateLabel: '25 de septiembre de 2026',
                amount: money('$30.00', 3_000),
                paid: money('$0.00', 0),
                status: 'pending',
                statusLabel: 'Pendiente',
            },
        ],
        payments: [],
        rate: { rate: '42.50000000', sourceLabel: 'BCV', publishedAt: '7 de octubre, 9:00 a. m.' },
        preview: PREVIEW,
        previewError: null,
        approval: {
            url: '/backoffice/comprobantes/9/aprobar',
            previewUrl: '/backoffice/comprobantes/9/vista-previa',
            currencies: [
                { value: 'USD', label: 'Dólares' },
                { value: 'VES', label: 'Bolívares' },
            ],
        },
        inboxUrl: '/backoffice/comprobantes',
        ...overrides,
    };
}
