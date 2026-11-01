import { formatRate } from '@/features/receipts/lib/inbox-format';
import { type PaymentFieldOptions } from '@/types/layaway-backoffice';
import { type ApprovalPreviewView } from '@/types/receipt';

export function dayLabel(iso: string): string {
    return `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;
}

export function appliedRateLine(options: PaymentFieldOptions, paidAt: string, preview: ApprovalPreviewView): string {
    const when = paidAt !== '' && paidAt < options.paidAt.max ? `, la del ${dayLabel(paidAt)}` : ', la vigente';

    return preview.rateSource === 'manual'
        ? `Se registra a ${formatRate(preview.rate)} Bs por dólar, la tasa que escribiste.`
        : `Se registra a ${formatRate(preview.rate)} Bs por dólar (${preview.rateSourceLabel}${when}).`;
}

export function rateHint(options: PaymentFieldOptions, paidAt: string, preview: ApprovalPreviewView | null): string {
    if (paidAt === '' || paidAt >= options.paidAt.max) {
        return options.rate === null
            ? 'No hay tasa vigente: escribe la que usaste.'
            : `Vacía usa la vigente: ${formatRate(options.rate.rate)} (${options.rate.sourceLabel}).`;
    }

    const day = dayLabel(paidAt);

    return preview !== null && preview.rateSource !== 'manual'
        ? `Vacía usa la del ${day}: ${formatRate(preview.rate)} (${preview.rateSourceLabel}).`
        : `Vacía usa la tasa oficial del ${day}.`;
}
