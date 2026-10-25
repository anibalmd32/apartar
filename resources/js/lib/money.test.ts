import { availableLabels, centsUsd, hasVes } from '@/lib/money';
import type { MoneyView } from '@/types/money';
import { describe, expect, it } from 'vitest';

function view(ves: string | null): MoneyView {
    return { cents: centsUsd(2000), usd: '$20,00', ves };
}

describe('céntimos como tipo de marca', () => {
    it('marca un entero sin tocar su valor', () => {
        expect(centsUsd(2000)).toBe(2000);
    });

    it('rechaza lo que no es entero en vez de redondearlo', () => {
        expect(() => centsUsd(19.99)).toThrow(TypeError);
        expect(() => centsUsd(19.99)).toThrow(/coma flotante/);
    });

    it('admite el negativo, que es el crédito', () => {
        expect(centsUsd(-500)).toBe(-500);
    });
});

describe('degradación sin tasa', () => {
    it('distingue «no hay proyección» de «vale cero»', () => {
        expect(hasVes(view(null))).toBe(false);
        expect(hasVes(view('Bs. 0,00'))).toBe(true);
        expect(hasVes(view('Bs. 850,00'))).toBe(true);
    });

    it('deja solo el dólar cuando no hay tasa', () => {
        expect(availableLabels(view(null))).toEqual(['$20,00']);
    });

    it('da las dos representaciones cuando las hay', () => {
        expect(availableLabels(view('Bs. 850,00'))).toEqual(['$20,00', 'Bs. 850,00']);
    });
});
