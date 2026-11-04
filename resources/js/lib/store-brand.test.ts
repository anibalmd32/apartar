import { storePalette } from '@/lib/store-brand';
import { describe, expect, it } from 'vitest';

function rgb(hex: string): [number, number, number] {
    const value = parseInt(hex.slice(1), 16);

    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

function ratio(a: string, b: string): number {
    const lum = (hex: string) => {
        const [r, g, b] = rgb(hex).map((channel) => {
            const value = channel / 255;

            return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }) as [number, number, number];

        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const [high, low] = [lum(a), lum(b)].sort((x, y) => y - x) as [number, number];

    return (high + 0.05) / (low + 0.05);
}

describe('el color de la tienda sale del negocio', () => {
    it('sin color de marca la tienda usa tinta neutra, no el azul de Apartar', () => {
        const palette = storePalette(null);

        expect(palette.light).toBe('#15171b');
        expect(palette.dark).toBe('#f2f3ef');
    });

    it('un color de marca claro se oscurece hasta leerse sobre el fondo claro, con letra que se lee encima', () => {
        const palette = storePalette('#ffd84a');

        expect(ratio(palette.light, '#f4f5f2')).toBeGreaterThanOrEqual(4.5);
        expect(ratio(palette.onLight, palette.light)).toBeGreaterThanOrEqual(4.5);
    });

    it('un color de marca oscuro se aclara para leerse sobre la pizarra negra', () => {
        const palette = storePalette('#3a0ca3');

        expect(palette.light).toBe('#3a0ca3');
        expect(palette.onLight).toBe('#ffffff');
        expect(ratio(palette.dark, '#0c0e11')).toBeGreaterThanOrEqual(4.5);
        expect(ratio(palette.onDark, palette.dark)).toBeGreaterThanOrEqual(4.5);
    });

    it('un color mal escrito se trata como si no hubiera marca', () => {
        expect(storePalette('rojo').light).toBe('#15171b');
    });
});
