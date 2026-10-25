export type AuditPeriod = 'today' | 'last7' | 'last30';

export const AUDIT_PERIODS: readonly { value: AuditPeriod; label: string; days: number }[] = [
    { value: 'today', label: 'Hoy', days: 1 },
    { value: 'last7', label: 'Últimos 7 días', days: 7 },
    { value: 'last30', label: 'Últimos 30 días', days: 30 },
];

export function isoDay(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function periodRange(period: AuditPeriod, now: Date = new Date()): { from: string; to: string } {
    const days = AUDIT_PERIODS.find((option) => option.value === period)?.days ?? 1;
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate() - (days - 1));

    return { from: isoDay(start), to: isoDay(now) };
}

export function periodOf(from: string, to: string, now: Date = new Date()): AuditPeriod | null {
    return (
        AUDIT_PERIODS.find((option) => {
            const range = periodRange(option.value, now);

            return range.from === from && range.to === to;
        })?.value ?? null
    );
}
