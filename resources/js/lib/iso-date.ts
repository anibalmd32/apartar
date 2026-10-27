export interface IsoParts {
    readonly year: number;
    readonly month: number;
    readonly day: number;
}

const ISO = /^(\d{4})-(\d{2})-(\d{2})$/;

export function parseIsoDate(value: string | null | undefined): IsoParts | null {
    const match = value ? ISO.exec(value) : null;

    if (!match) {
        return null;
    }

    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const date = new Date(year, month - 1, day);

    return date.getMonth() === month - 1 && date.getDate() === day ? { year, month, day } : null;
}

export function toIsoDate(year: number, month: number, day: number): string {
    const date = new Date(year, month - 1, day);

    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

export function shiftIsoDate(value: string, days: number): string {
    const parts = parseIsoDate(value);

    return parts ? toIsoDate(parts.year, parts.month, parts.day + days) : value;
}

export function todayIso(): string {
    const now = new Date();

    return toIsoDate(now.getFullYear(), now.getMonth() + 1, now.getDate());
}

export function formatIsoDate(value: string | null | undefined): string | null {
    const parts = parseIsoDate(value);

    return parts ? `${String(parts.day).padStart(2, '0')}/${String(parts.month).padStart(2, '0')}/${parts.year}` : null;
}

export function isWithin(value: string, min?: string | null, max?: string | null): boolean {
    return (!min || value >= min) && (!max || value <= max);
}
