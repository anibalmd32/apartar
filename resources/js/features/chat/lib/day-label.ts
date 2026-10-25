export function dayKey(iso: string | Date): string {
    const date = typeof iso === 'string' ? new Date(iso) : iso;

    return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
}

export function dayLabel(iso: string, now: Date = new Date()): string {
    const date = new Date(iso);
    const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);

    if (dayKey(date) === dayKey(now)) {
        return 'Hoy';
    }

    if (dayKey(date) === dayKey(yesterday)) {
        return 'Ayer';
    }

    const options: Intl.DateTimeFormatOptions =
        date.getFullYear() === now.getFullYear()
            ? { weekday: 'long', day: 'numeric', month: 'long' }
            : { day: 'numeric', month: 'long', year: 'numeric' };
    const label = new Intl.DateTimeFormat('es-VE', options).format(date);

    return label.charAt(0).toUpperCase() + label.slice(1);
}
