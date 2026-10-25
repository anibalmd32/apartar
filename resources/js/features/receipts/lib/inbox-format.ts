import { type Currency } from '@/types/money';

export function formatDeclaredAmount(amount: string, currency: Currency): string {
    const [whole = '0', fraction = ''] = amount.split('.');
    const cents = fraction.padEnd(2, '0').slice(0, 2);
    const negative = whole.startsWith('-');
    const digits = negative ? whole.slice(1) : whole;

    const grouped = (separator: string) => digits.replace(/\B(?=(\d{3})+(?!\d))/g, separator);
    const sign = negative ? '-' : '';

    return currency === 'USD' ? `${sign}$${grouped('.')},${cents}` : `${sign}Bs. ${grouped('.')},${cents}`;
}

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

export function formatAge(submittedAt: string, now: number): string {
    const elapsed = Math.max(0, now - Date.parse(submittedAt));

    if (elapsed < HOUR) {
        return `hace ${Math.floor(elapsed / MINUTE)} min`;
    }

    if (elapsed < 2 * DAY) {
        return `hace ${Math.floor(elapsed / HOUR)} h`;
    }

    return `hace ${Math.floor(elapsed / DAY)} d`;
}

export function formatDay(day: string): string {
    const [year, month, date] = day.split('-');

    return `${date}/${month}/${year}`;
}

export function formatRate(rate: string): string {
    const [whole = '0', fraction = ''] = rate.split('.');
    const trimmed = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${trimmed}`;
}
