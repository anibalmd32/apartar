const NUMBER = new Intl.NumberFormat('es-VE');
const PERCENT = new Intl.NumberFormat('es-VE', { style: 'percent', maximumFractionDigits: 1 });
const DATE_TIME = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });
const SHORT = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short' });
const TIME = new Intl.DateTimeFormat('es', { hour: '2-digit', minute: '2-digit' });

export const DASH = '—';

export function count(value: number): string {
    return NUMBER.format(value);
}

export function percent(rate: number | null): string {
    return rate === null ? DASH : PERCENT.format(rate);
}

export function dateTime(iso: string): string {
    return DATE_TIME.format(new Date(iso));
}

export function shortDate(iso: string, now: Date = new Date()): string {
    const date = new Date(iso);

    return date.toDateString() === now.toDateString() ? TIME.format(date) : SHORT.format(date);
}

export function fileSize(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    return bytes < 1_048_576 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1_048_576).toLocaleString('es-VE', { maximumFractionDigits: 1 })} MB`;
}

export function minutes(value: number | null): string {
    if (value === null) {
        return DASH;
    }

    if (value < 60) {
        return `${value} min`;
    }

    const hours = value / 60;

    return hours < 48 ? `${hours.toLocaleString('es-VE', { maximumFractionDigits: 1 })} h` : `${Math.round(hours / 24)} d`;
}

export type StatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'error';

const TONES: Record<string, StatusTone> = {
    sending: 'neutral',
    sent: 'info',
    delivered: 'success',
    opened: 'success',
    clicked: 'success',
    soft_bounced: 'warning',
    unsubscribed: 'warning',
    spam: 'error',
    bounced: 'error',
    rejected: 'error',
    suspended: 'error',
    failed: 'error',
};

export function statusTone(status: string): StatusTone {
    return TONES[status] ?? 'neutral';
}
