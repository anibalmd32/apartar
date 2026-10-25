const pad = (value: number) => String(value).padStart(2, '0');
const time = (date: Date) => `${pad(date.getHours())}:${pad(date.getMinutes())}`;
const day = (date: Date) => `${pad(date.getDate())}/${pad(date.getMonth() + 1)}`;

function dayOffset(date: Date, now: Date): number {
    const startOf = (value: Date) => new Date(value.getFullYear(), value.getMonth(), value.getDate()).getTime();

    return Math.round((startOf(date) - startOf(now)) / 86_400_000);
}

export function formatMoment(iso: string, now: Date = new Date()): string {
    const date = new Date(iso);
    const offset = dayOffset(date, now);
    const words: Record<number, string> = { [-1]: 'ayer', 0: 'hoy', 1: 'mañana' };

    return offset in words ? `${words[offset]} ${time(date)}` : day(date);
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toLocaleString('es-VE', { maximumFractionDigits: 1 })} MB`;
}
