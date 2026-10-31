import { ratePending } from '@/features/receipts/components/StaleRateConfirmation';
import { type ApprovalPreviewState } from '@/features/receipts/hooks/useApprovalPreview';
import { BYTES_PER_MB } from '@/features/receipts/hooks/useFilePreview';
import { type ReceiptReading } from '@/features/receipts/hooks/useReceiptReading';
import { type PaymentFieldOptions } from '@/types/layaway-backoffice';

export interface PaymentFieldValues {
    method: string;
    payment_account: string;
    amount: string;
    currency: string;
    rate: string;
    paid_at: string;
    bank: string;
    reference: string;
    file: File | null;
    note: string;
    confirm_excess: number | null;
    confirm_stale_rate: string | null;
    confirm_rate_deviation: string | null;
}

export type PaymentFieldErrors = Partial<Record<keyof PaymentFieldValues, string>>;

export function emptyPaymentValues(options: PaymentFieldOptions): PaymentFieldValues {
    return {
        method: '',
        payment_account: '',
        amount: '',
        currency: 'USD',
        rate: '',
        paid_at: options.paidAt.max,
        bank: '',
        reference: '',
        file: null,
        note: '',
        confirm_excess: null,
        confirm_stale_rate: null,
        confirm_rate_deviation: null,
    };
}

export function requiresReference(options: PaymentFieldOptions, method: string): boolean {
    return options.methods.find((option) => option.value === method)?.requiresReference ?? false;
}

export function fileTooLarge(options: PaymentFieldOptions, file: File | null): boolean {
    return file !== null && file.size > options.file.maxMb * BYTES_PER_MB;
}

export function paymentPending(options: PaymentFieldOptions, values: PaymentFieldValues, { preview, loading }: ApprovalPreviewState): boolean {
    const excess = preview?.unallocated ?? null;
    const excessPending = preview !== null && excess !== null && values.confirm_excess !== preview.unallocatedCents;
    const referencePending = requiresReference(options, values.method) && (values.bank.trim() === '' || values.reference.trim() === '');

    return (
        loading ||
        values.method === '' ||
        referencePending ||
        fileTooLarge(options, values.file) ||
        excessPending ||
        ratePending(preview, { confirm_stale_rate: values.confirm_stale_rate, confirm_rate_deviation: values.confirm_rate_deviation })
    );
}

export function patchFromReading(options: PaymentFieldOptions, values: PaymentFieldValues, reading: ReceiptReading): Partial<PaymentFieldValues> {
    return {
        amount: reading.amount ?? '',
        ...(reading.currency === null ? {} : { currency: reading.currency, rate: reading.currency === 'USD' ? '' : values.rate }),
        paid_at: reading.paidAt ?? values.paid_at,
        ...(requiresReference(options, values.method) ? { bank: reading.bank ?? '', reference: reading.reference ?? '' } : {}),
    };
}
