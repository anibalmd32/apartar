import { formatRate } from '@/features/receipts/lib/inbox-format';
import { type PaymentFieldOptions } from '@/types/layaway-backoffice';
import { type ApprovalPreviewView } from '@/types/receipt';

export function dayLabel(iso: string): string {
    return `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;
}

export function appliedRateLine(options: PaymentFieldOptions, paidAt: string, preview: ApprovalPreviewView): string {
    const when = paidAt !== '' && paidAt < options.paidAt.max ? `, la del ${dayLabel(paidAt)}` : ', la vigente';

    return preview.rateSource === 'manual'
        ? `Equivale a ${preview.amount}: a ${formatRate(preview.rate)} Bs por dólar, la tasa que escribiste.`
        : `Equivale a ${preview.amount}: a ${formatRate(preview.rate)} Bs por dólar (${preview.rateSourceLabel}${when}).`;
}
