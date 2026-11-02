import { type BadgeTone } from '@/components/ui/badge';

type ToneMap = Readonly<Record<string, BadgeTone>>;

export const LAYAWAY_TONES = {
    pending_down_payment: 'warning',
    active: 'info',
    overdue: 'error',
    completed: 'success',
    cancelled: 'neutral',
    expired: 'neutral',
} as const satisfies ToneMap;

export const INSTALLMENT_TONES = {
    pending: 'neutral',
    soon: 'warning',
    partially_paid: 'info',
    partial: 'info',
    paid: 'success',
    overdue: 'error',
} as const satisfies ToneMap;

export const RECEIPT_TONES = {
    pending_review: 'warning',
    approved: 'success',
    rejected: 'error',
} as const satisfies ToneMap;

export const INVOICE_TONES = {
    pending: 'info',
    under_review: 'warning',
    paid: 'success',
    overdue: 'error',
    void: 'neutral',
} as const satisfies ToneMap;

export const ORGANIZATION_TONES = {
    active: 'success',
    suspended: 'error',
    closed: 'neutral',
} as const satisfies ToneMap;

export const SUBSCRIPTION_TONES = {
    active: 'success',
    past_due: 'warning',
    grace: 'warning',
    suspended: 'error',
    cancelled: 'neutral',
} as const satisfies ToneMap;

export function toneOf(map: ToneMap, status: string | null | undefined): BadgeTone {
    return (status !== null && status !== undefined ? map[status] : undefined) ?? 'neutral';
}
