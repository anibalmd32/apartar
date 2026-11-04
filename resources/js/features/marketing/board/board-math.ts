export interface DailyRate {
    date: string;
    rate: string;
}

export interface SumRow {
    label: string;
    due: string | null;
    cents: number;
    paid: boolean;
}

export interface LayawaySum {
    rows: SumRow[];
    remainingCents: number;
}

export const EXAMPLE_PRICE_CENTS = 4800;

export const EXAMPLE_DOWN_PAYMENT_CENTS = 1200;

export const INSTALLMENT_CHOICES = [2, 3, 4] as const;

export type InstallmentChoice = (typeof INSTALLMENT_CHOICES)[number];

const DAYS_BETWEEN_INSTALLMENTS = 15;

const PAID_INSTALLMENTS = 1;

const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const SHORT_MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

const usd = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function formatUsd(cents: number): string {
    return `$${usd.format(cents / 100)}`;
}

export function shownRate(rate: string): number {
    return Math.round(Number(rate) * 100) / 100;
}

export function formatBs(cents: number, rate: string): string {
    return `Bs. ${usd.format(Math.round(cents * shownRate(rate)) / 100)}`;
}

export function formatRate(rate: string): string {
    return `Bs. ${usd.format(Number(rate))}`;
}

function utcDay(iso: string): Date {
    const [year, month, day] = iso.split('-').map(Number);

    return new Date(Date.UTC(year!, month! - 1, day!));
}

export function toIsoDay(date: Date): string {
    return date.toISOString().slice(0, 10);
}

export function caracasToday(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Caracas', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

export function longDay(iso: string): string {
    const date = utcDay(iso);

    return `${WEEKDAYS[date.getUTCDay()]} ${date.getUTCDate()} de ${MONTHS[date.getUTCMonth()]}`;
}

export function shortDay(iso: string): string {
    const date = utcDay(iso);

    return `${date.getUTCDate()} ${SHORT_MONTHS[date.getUTCMonth()]}`;
}

const SHORT_WEEKDAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

export function weekdayDay(iso: string): string {
    return `${SHORT_WEEKDAYS[utcDay(iso).getUTCDay()]} ${shortDay(iso)}`;
}

export function missingDays(newer: string, older: string): string[] {
    const missing: string[] = [];

    for (let day = addDays(older, 1); day < newer; day = addDays(day, 1)) {
        missing.push(day);
    }

    return missing;
}

function addDays(iso: string, days: number): string {
    const date = utcDay(iso);
    date.setUTCDate(date.getUTCDate() + days);

    return toIsoDay(date);
}

export function layawaySum(installments: InstallmentChoice, startDay: string): LayawaySum {
    const installmentCents = Math.round((EXAMPLE_PRICE_CENTS - EXAMPLE_DOWN_PAYMENT_CENTS) / installments);
    const firstDue = addDays(startDay, -DAYS_BETWEEN_INSTALLMENTS * PAID_INSTALLMENTS);

    const rows: SumRow[] = [
        { label: 'Inicial', due: null, cents: EXAMPLE_DOWN_PAYMENT_CENTS, paid: true },
        ...Array.from({ length: installments }, (_, index) => ({
            label: `Cuota ${index + 1}`,
            due: addDays(firstDue, DAYS_BETWEEN_INSTALLMENTS * (index + 1)),
            cents: installmentCents,
            paid: index < PAID_INSTALLMENTS,
        })),
    ];

    const remainingCents = rows.filter((row) => !row.paid).reduce((total, row) => total + row.cents, 0);

    return { rows, remainingCents };
}
