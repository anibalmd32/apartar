import { parseIsoDate } from '@/lib/iso-date';

export type DateStyle = 'short' | 'long' | 'numeric' | 'dayMonth';

interface Moment {
    readonly year: number;
    readonly month: number;
    readonly day: number;
    readonly hour: number;
    readonly minute: number;
}

interface MomentOptions {
    readonly now?: Date;
    readonly timeZone?: string;
    readonly inSentence?: boolean;
}

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const SHORT_MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

const NBSP = '\u00a0';

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

const zoned = new Map<string, Intl.DateTimeFormat>();

function zoneFormat(timeZone: string | undefined): Intl.DateTimeFormat {
    const key = timeZone ?? '';
    const cached = zoned.get(key);

    if (cached !== undefined) {
        return cached;
    }

    const format = new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        hourCycle: 'h23',
        timeZone,
    });
    zoned.set(key, format);

    return format;
}

function momentOf(value: string | Date, timeZone?: string): Moment | null {
    if (typeof value === 'string' && ISO_DAY.test(value)) {
        const parts = parseIsoDate(value);

        return parts === null ? null : { ...parts, hour: 0, minute: 0 };
    }

    const date = typeof value === 'string' ? new Date(value) : value;

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts = zoneFormat(timeZone).formatToParts(date);
    const piece = (type: Intl.DateTimeFormatPartTypes) => Number(parts.find((part) => part.type === type)?.value ?? 0);

    return { year: piece('year'), month: piece('month'), day: piece('day'), hour: piece('hour') % 24, minute: piece('minute') };
}

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function dateOf(moment: Moment, style: DateStyle): string {
    switch (style) {
        case 'numeric':
            return `${pad(moment.day)}/${pad(moment.month)}/${moment.year}`;
        case 'long':
            return `${moment.day} de ${MONTHS[moment.month - 1]} de ${moment.year}`;
        case 'dayMonth':
            return `${moment.day} ${SHORT_MONTHS[moment.month - 1]}`;
        default:
            return `${moment.day} ${SHORT_MONTHS[moment.month - 1]} ${moment.year}`;
    }
}

function timeOf(moment: Moment): string {
    const hour = moment.hour % 12 === 0 ? 12 : moment.hour % 12;

    return `${hour}:${pad(moment.minute)}${NBSP}${moment.hour < 12 ? `a.${NBSP}m.` : `p.${NBSP}m.`}`;
}

function dayDistance(moment: Moment, now: Moment): number {
    return Math.round((Date.UTC(moment.year, moment.month - 1, moment.day) - Date.UTC(now.year, now.month - 1, now.day)) / 86_400_000);
}

export function formatDate(value: string | Date, style: DateStyle = 'short', timeZone?: string): string {
    const moment = momentOf(value, timeZone);

    return moment === null ? String(value) : dateOf(moment, style);
}

export function formatTime(value: string | Date, timeZone?: string): string {
    const moment = momentOf(value, timeZone);

    return moment === null ? String(value) : timeOf(moment);
}

export function formatDateTime(value: string | Date, timeZone?: string): string {
    const moment = momentOf(value, timeZone);

    return moment === null ? String(value) : `${dateOf(moment, 'short')}, ${timeOf(moment)}`;
}

export function formatPeriod(start: string | Date, end: string | Date, timeZone?: string): string {
    const from = momentOf(start, timeZone);
    const to = momentOf(end, timeZone);

    if (from === null || to === null) {
        return `${String(start)} – ${String(end)}`;
    }

    return `${dateOf(from, from.year === to.year ? 'dayMonth' : 'short')} – ${dateOf(to, 'short')}`;
}

export function formatRelative(value: string | Date, { now = new Date(), timeZone, inSentence = false }: MomentOptions = {}): string {
    const moment = momentOf(value, timeZone);
    const today = momentOf(now, timeZone);

    if (moment === null || today === null) {
        return String(value);
    }

    const words: Record<number, string> = { [-1]: 'Ayer', 0: 'Hoy', 1: 'Mañana' };
    const distance = dayDistance(moment, today);

    if (distance in words) {
        const word = words[distance] ?? '';

        return `${inSentence ? word.toLowerCase() : word} ${timeOf(moment)}`;
    }

    return dateOf(moment, moment.year === today.year ? 'dayMonth' : 'short');
}

export function formatMoment(value: string | Date, { now = new Date(), timeZone }: MomentOptions = {}): string {
    const moment = momentOf(value, timeZone);
    const today = momentOf(now, timeZone);

    if (moment === null || today === null) {
        return String(value);
    }

    if (dayDistance(moment, today) === 0) {
        return timeOf(moment);
    }

    return dateOf(moment, moment.year === today.year ? 'dayMonth' : 'short');
}
