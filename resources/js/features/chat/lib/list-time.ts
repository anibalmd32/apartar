import { formatRelative } from '@/lib/dates';

export function listTime(iso: string, now: Date = new Date()): string {
    const label = formatRelative(iso, { now });

    return label.startsWith('Ayer') ? 'Ayer' : label;
}
