import { type RateSource, type RateView } from '@/types/exchange';
import { describe, expect, it } from 'vitest';

describe('contrato de la tasa en las page props', () => {
    it('nombra las tres fuentes de RateSource', () => {
        const sources: RateSource[] = ['bcv', 'parallel', 'manual'];

        expect(sources).toHaveLength(3);
    });

    it('escribe la ausencia de tasa como null y nunca como cero', () => {
        const degraded: RateView = {
            rate: null,
            source: 'bcv',
            sourceLabel: 'BCV',
            publishedAt: null,
            isStale: false,
        };

        expect(degraded.rate).toBeNull();
        expect(degraded.rate).not.toBe(0);
    });

    it('lleva la fecha en ISO 8601 y el sello de obsolescencia', () => {
        const stale: RateView = {
            rate: 42.5,
            source: 'parallel',
            sourceLabel: 'Paralelo',
            publishedAt: '2026-09-18T10:30:00.000Z',
            isStale: true,
        };

        expect(new Date(stale.publishedAt ?? '').toISOString()).toBe('2026-09-18T10:30:00.000Z');
        expect(stale.isStale).toBe(true);
    });
});
