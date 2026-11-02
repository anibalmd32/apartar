import { describe, expect, it } from 'vitest';
import { TIMEZONE_OPTIONS, timezoneOptions } from './timezones';

describe('zonas horarias del negocio', () => {
    it('empieza por la hora de Caracas con un nombre legible', () => {
        expect(TIMEZONE_OPTIONS[0]).toEqual({ value: 'America/Caracas', label: 'Hora de Caracas' });
    });

    it('conserva una zona guardada que no está en la lista', () => {
        expect(timezoneOptions('Asia/Tokyo').at(-1)).toEqual({ value: 'Asia/Tokyo', label: 'Asia/Tokyo' });
        expect(timezoneOptions('America/Bogota')).toBe(TIMEZONE_OPTIONS);
    });
});
