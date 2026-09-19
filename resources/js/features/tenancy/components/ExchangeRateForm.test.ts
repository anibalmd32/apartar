import { publishedAgo } from '@/features/tenancy/components/ExchangeRateForm';
import { describe, expect, it } from 'vitest';

/**
 * La antigüedad que la pantalla de ajustes enseña junto a cada fuente
 * (`REQ-FX-003` RB-6).
 *
 * **Se mide sobre `publishedAt`**, que es cuándo la publicó la fuente, y no
 * sobre cuándo corrió nuestra ingesta. Es la misma corrección que `T-019`
 * llevó al contrato de `RateView`: con la otra fecha, un fin de semana sin
 * publicación diría «hace 5 min» sobre una tasa del viernes, que es
 * exactamente el engaño que tener dos columnas venía a evitar.
 */
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

    /**
     * SP-4: una fuente sin tasa se puede elegir igual, así que la pantalla
     * tiene que poder escribir su ausencia sin inventarse una fecha.
     */
    it('no inventa una fecha cuando no hay tasa', () => {
        expect(publishedAgo(null, now)).toBe('sin fecha');
    });
});
