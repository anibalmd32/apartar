import { publishedAgo } from '@/features/currency/lib/rateAge';
import { describe, expect, it } from 'vitest';

describe('antigüedad de la tasa en el selector de fuente', () => {
    const now = new Date('2026-09-19T12:00:00.000Z');

    it('cuenta los minutos del ejemplo de la spec', () => {
        expect(publishedAgo('2026-09-19T11:48:00.000Z', now)).toBe('hace 12 min');
    });

    it('dice «hace un momento» por debajo del minuto', () => {
        expect(publishedAgo('2026-09-19T11:59:30.000Z', now)).toBe('hace un momento');
    });

    it('pasa a horas y a días', () => {
        expect(publishedAgo('2026-09-19T09:00:00.000Z', now)).toBe('hace 3 h');
        expect(publishedAgo('2026-09-17T12:00:00.000Z', now)).toBe('hace 2 d');
    });

    it('no inventa una fecha cuando no hay tasa', () => {
        expect(publishedAgo(null, now)).toBe('sin fecha');
    });
});
