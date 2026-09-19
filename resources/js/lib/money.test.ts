import { availableLabels, centsUsd, hasVes } from '@/lib/money';
import type { MoneyView } from '@/types/money';
import { describe, expect, it } from 'vitest';

/**
 * El dinero en la interfaz (`REQ-FX-001` RB-6, `REQ-FX-005` RB-4).
 *
 * Lo que se prueba aquí es presentación sobre importes **ya resueltos**: la
 * conversión va por `bcmul` en el servidor y el formateo por
 * `MoneyFormatter`. Si algún día aparece una multiplicación en `lib/money.ts`,
 * es que la cuenta se fue al sitio equivocado.
 */
function view(ves: string | null): MoneyView {
    return { cents: centsUsd(2000), usd: '$20.00', ves };
}

describe('céntimos como tipo de marca', () => {
    it('marca un entero sin tocar su valor', () => {
        expect(centsUsd(2000)).toBe(2000);
    });

    /**
     * No redondea ni corrige: un importe fraccionario significa que alguien
     * convirtió con coma flotante, y el arreglo está del lado del servidor.
     */
    it('rechaza lo que no es entero en vez de redondearlo', () => {
        expect(() => centsUsd(19.99)).toThrow(TypeError);
        expect(() => centsUsd(19.99)).toThrow(/coma flotante/);
    });

    it('admite el negativo, que es el crédito', () => {
        expect(centsUsd(-500)).toBe(-500);
    });
});

describe('degradación sin tasa', () => {
    /**
     * `REQ-FX-005` RB-4: sin tasa, la interfaz **oculta** el monto en VES. Ni
     * `Bs. 0,00` ni un guion: las dos cosas ponen en pantalla un precio que
     * nadie ha calculado.
     */
    it('distingue «no hay proyección» de «vale cero»', () => {
        expect(hasVes(view(null))).toBe(false);
        expect(hasVes(view('Bs. 0,00'))).toBe(true);
        expect(hasVes(view('Bs. 850,00'))).toBe(true);
    });

    it('deja solo el dólar cuando no hay tasa', () => {
        expect(availableLabels(view(null))).toEqual(['$20.00']);
    });

    it('da las dos representaciones cuando las hay', () => {
        expect(availableLabels(view('Bs. 850,00'))).toEqual(['$20.00', 'Bs. 850,00']);
    });
});
