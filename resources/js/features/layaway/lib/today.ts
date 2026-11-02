export const BUSINESS_TIMEZONE = 'America/Caracas';

export function todayIn(timeZone: string = BUSINESS_TIMEZONE, now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone }).format(now);
}
