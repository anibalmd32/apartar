import { formatLimit, formatUsage, isUnlimited, usagePercentage } from '@/lib/plan';
import { describe, expect, it } from 'vitest';

/**
 * `REQ-SUB-001` RB-2 y SP-2: `null` es ilimitado en todo el camino, incluido
 * el último tramo, que es donde se estropea.
 */
describe('límites de plan en la interfaz', () => {
    it('dice «ilimitado» y no cero cuando no hay techo', () => {
        expect(formatLimit(null)).toBe('ilimitado');
        expect(formatLimit(null)).not.toBe('0');
        expect(isUnlimited(null)).toBe(true);
    });

    it('distingue el sin techo del techo en cero', () => {
        expect(isUnlimited(0)).toBe(false);
        expect(formatLimit(0)).toBe('0');
    });

    it('formatea la cifra del techo', () => {
        expect(formatLimit(30)).toBe('30');
        expect(formatLimit(2000)).toContain('2');
    });

    it('lee el consumo como «usado de disponible»', () => {
        expect(formatUsage(4, 30)).toBe('4 de 30');
        expect(formatUsage(4, null)).toBe('4 de ilimitado');
    });

    it('no calcula porcentaje sobre un plan sin techo', () => {
        // Una barra al 100 % en un plan ilimitado sería una alarma inventada;
        // al 0 %, una que nunca avisa. No hay porcentaje que dar.
        expect(usagePercentage(500, null)).toBeNull();
    });

    it('calcula el porcentaje y lo acota al cien', () => {
        expect(usagePercentage(15, 30)).toBe(50);
        expect(usagePercentage(45, 30)).toBe(100);
        expect(usagePercentage(0, 30)).toBe(0);
    });
});
