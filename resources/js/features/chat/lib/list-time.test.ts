import { listTime } from '@/features/chat/lib/list-time';
import { expect, it } from 'vitest';

it('las listas de conversaciones dicen «Hoy» con la hora, «Ayer» o la fecha corta', () => {
    const now = new Date(2026, 10, 2, 22, 0);

    expect(listTime(new Date(2026, 10, 2, 21, 45).toISOString(), now)).toBe('Hoy 9:45 p. m.');
    expect(listTime(new Date(2026, 10, 1, 8, 10).toISOString(), now)).toBe('Ayer');
    expect(listTime(new Date(2026, 9, 28, 8, 10).toISOString(), now)).toBe('28 oct');
});
