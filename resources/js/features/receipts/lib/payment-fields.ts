import { ratePending } from '@/features/receipts/components/StaleRateConfirmation';
import { type ApprovalPreviewState } from '@/features/receipts/hooks/useApprovalPreview';
import { BYTES_PER_MB } from '@/features/receipts/hooks/useFilePreview';
import { type ReceiptReading } from '@/features/receipts/hooks/useReceiptReading';
import { amountInput } from '@/lib/money';
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

export const NOTE_MIN_LENGTH = 10;

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

export function paymentMissing(options: PaymentFieldOptions, values: PaymentFieldValues, { preview, loading }: ApprovalPreviewState): string | null {
    const excess = preview?.unallocated ?? null;
    const excessPending = preview !== null && excess !== null && values.confirm_excess !== preview.unallocatedCents;
    const referencePending = requiresReference(options, values.method) && (values.bank.trim() === '' || values.reference.trim() === '');
    const checks: readonly [boolean, string][] = [
        [values.method === '', 'Elige el método del pago.'],
        [referencePending, 'Escribe el banco y el número de referencia del pago.'],
        [fileTooLarge(options, values.file), `El comprobante pasa de ${options.file.maxMb} MB.`],
        [
            options.noteRequired === true && values.note.trim().length < NOTE_MIN_LENGTH,
            `Escribe el motivo, de al menos ${NOTE_MIN_LENGTH} caracteres.`,
        ],
        [loading, 'Estamos calculando a qué cuotas va el pago.'],
        [excessPending, 'Confirma que registras el pago con lo que sobra sin asignar.'],
        [
            ratePending(preview, { confirm_stale_rate: values.confirm_stale_rate, confirm_rate_deviation: values.confirm_rate_deviation }),
            'Confirma la tasa del pago.',
        ],
    ];

    return checks.find(([pending]) => pending)?.[1] ?? null;
}

export function paymentPending(options: PaymentFieldOptions, values: PaymentFieldValues, state: ApprovalPreviewState): boolean {
    return paymentMissing(options, values, state) !== null;
}

export function patchFromReading(options: PaymentFieldOptions, values: PaymentFieldValues, reading: ReceiptReading): Partial<PaymentFieldValues> {
    return {
        amount: reading.amount === null ? '' : amountInput(reading.amount),
        ...(reading.currency === null ? {} : { currency: reading.currency, rate: reading.currency === 'USD' ? '' : values.rate }),
        paid_at: reading.paidAt ?? values.paid_at,
        ...(requiresReference(options, values.method) ? { bank: reading.bank ?? '', reference: reading.reference ?? '' } : {}),
    };
}
