export function publishedAgo(publishedAt: string | null, now: Date = new Date()): string {
    if (publishedAt === null) {
        return 'sin fecha';
    }

    const minutes = Math.floor((now.getTime() - new Date(publishedAt).getTime()) / 60_000);

    if (minutes < 1) {
        return 'hace un momento';
    }

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `hace ${hours} h`;
    }

    return `hace ${Math.floor(hours / 24)} d`;
}

export function publishedStamp(publishedAt: string | null, timezone: string): string | null {
    if (publishedAt === null) {
        return null;
    }

    const at = new Date(publishedAt);

    if (Number.isNaN(at.getTime())) {
        return null;
    }

    const parts = new Intl.DateTimeFormat('en-US', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: timezone,
    }).formatToParts(at);

    const piece = (type: Intl.DateTimeFormatPartTypes): string => (parts.find((part) => part.type === type)?.value ?? '').padStart(2, '0');

    return `${piece('day')}/${piece('month')} ${piece('hour')}:${piece('minute')}`;
}
