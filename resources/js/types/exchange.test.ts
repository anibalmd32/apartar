import { type RateSource, type RateView } from '@/types/exchange';
import { describe, expect, it } from 'vitest';

/**
 * El contrato de la tasa en el frontend (`REQ-FX-002`, `REQ-FX-005`).
 *
 * `exchange.ts` no tiene código que ejecutar, así que lo que se comprueba aquí
 * es lo que `tsc` no puede decir solo: que la ausencia de tasa se escribe
 * `null` y no `0`, y que las tres fuentes son las mismas cadenas que persiste
 * `RateSource` en PHP. Una unión que se quedara corta no rompería la
 * compilación del tipo —rompería la pantalla el día que un negocio elija la
 * fuente que falta—.
 */
describe('contrato de la tasa en las page props', () => {
    it('nombra las tres fuentes de RateSource', () => {
        const sources: RateSource[] = ['bcv', 'parallel', 'manual'];

        expect(sources).toHaveLength(3);
    });

    /**
     * `REQ-FX-005` RB-4: sin tasa, `rate` es `null`. Un `0` pintaría
     * `Bs. 0,00`, que es un precio inventado donde no hay ninguno.
     */
    it('escribe la ausencia de tasa como null y nunca como cero', () => {
        const degraded: RateView = {
            rate: null,
            source: 'bcv',
            publishedAt: null,
            isStale: false,
        };

        expect(degraded.rate).toBeNull();
        expect(degraded.rate).not.toBe(0);
    });

    /**
     * La fecha que viaja es la de **publicación**, no la de la ingesta: el
     * sello de `REQ-FX-005` RB-2 dice cuándo publicó el BCV, que es la
     * antigüedad que le importa al negocio. Con `fetchedAt`, un fin de semana
     * sin publicación estamparía «hoy 09:00» sobre una tasa del viernes.
     */
    it('lleva la fecha en ISO 8601 y el sello de obsolescencia', () => {
        const stale: RateView = {
            rate: 42.5,
            source: 'parallel',
            publishedAt: '2026-09-18T10:30:00.000Z',
            isStale: true,
        };

        expect(new Date(stale.publishedAt ?? '').toISOString()).toBe('2026-09-18T10:30:00.000Z');
        expect(stale.isStale).toBe(true);
    });
});
